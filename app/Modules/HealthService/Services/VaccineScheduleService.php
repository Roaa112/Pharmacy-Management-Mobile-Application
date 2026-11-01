<?php

namespace App\Modules\HealthService\Services;

use App\Models\Child;
use App\Models\Vaccine;
use App\Models\VaccineScheduleItem;
use App\Modules\HealthService\DefaultHealthContent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a child's vaccination schedule from the dashboard-managed template.
 *
 * Each vaccine row is a snapshot (name, description, date, reminder lead time, template item and
 * version), so editing or removing a template entry later never changes a child's existing
 * schedule. If the template is empty for the market, the schedule the app has always used is
 * generated (the same entries the migration seeded), so a child always gets a schedule.
 */
class VaccineScheduleService
{
    /** The active schedule (the store operates in Egypt only: one schedule). */
    public function template(): Collection
    {
        return VaccineScheduleItem::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return Collection<int, Vaccine> */
    public function generateFor(Child $child): Collection
    {
        $birthDate = Carbon::parse($child->birth_date)->startOfDay();
        $template = $this->template();

        return DB::transaction(function () use ($child, $birthDate, $template) {
            if ($template->isEmpty()) {
                return $this->generateBuiltIn($child, $birthDate);
            }

            return $template->map(fn (VaccineScheduleItem $item) => Vaccine::create([
                'child_id' => $child->id,
                'vaccine_schedule_item_id' => $item->id,
                'schedule_version' => $item->version,
                'vaccine_name' => $item->name_ar,
                'vaccine_name_en' => $item->name_en,
                'description' => $item->description_ar,
                'description_en' => $item->description_en,
                'scheduled_date' => $item->scheduledDateFor($birthDate),
                'reminder_days_before' => $item->reminder_days_before,
                'is_completed' => false,
            ]));
        });
    }

    private function generateBuiltIn(Child $child, Carbon $birthDate): Collection
    {
        return collect(DefaultHealthContent::DEFAULT_VACCINES)->map(fn ($row) => Vaccine::create([
            'child_id' => $child->id,
            'vaccine_name' => $row[3],
            'vaccine_name_en' => $row[4],
            'description' => $row[5],
            'description_en' => $row[6],
            'scheduled_date' => $birthDate->copy()->add("{$row[1]} {$row[2]}s"),
            'reminder_days_before' => 3,
            'is_completed' => false,
        ]));
    }
}
