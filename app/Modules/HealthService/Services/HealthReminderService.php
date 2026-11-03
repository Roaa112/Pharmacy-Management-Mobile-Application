<?php

namespace App\Modules\HealthService\Services;

use App\Jobs\SendHealthReminder;
use App\Models\Medication;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\Vaccine;
use App\Support\StoreClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds health reminders that are due and queues one job per reminder.
 *
 * Duplicates are impossible: before queueing, a notification_deliveries row is inserted with a
 * unique key (type, reference, occurrence). If it already exists — sent earlier, or claimed by a
 * scheduler running at the same time — the reminder is skipped.
 */
class HealthReminderService
{
    public const VACCINE = 'vaccine_reminder';
    public const MEDICATION = 'medication_reminder';

    /** Medication reminders are sent for doses due in the last MEDICATION_WINDOW_MINUTES. */
    public const MEDICATION_WINDOW_MINUTES = 15;

    /**
     * Vaccines not yet given whose date is between today and today + their reminder lead time.
     * The window (not one exact day) means a day the scheduler did not run is caught up.
     */
    public function queueVaccineReminders(?Carbon $today = null): int
    {
        if (!Setting::current()->vaccine_reminders_enabled) {
            return 0;
        }

        $today = ($today ?? StoreClock::now())->copy()->startOfDay();
        $queued = 0;

        Vaccine::with('child:id,user_id,child_name')
            ->where('is_completed', false)
            ->whereDate('scheduled_date', '>=', $today->toDateString())
            ->whereDate('scheduled_date', '<=', $today->copy()->addDays(30)->toDateString())
            ->chunkById(500, function ($vaccines) use ($today, &$queued) {
                foreach ($vaccines as $vaccine) {
                    $lead = $vaccine->reminder_days_before ?? 3;
                    $due = $vaccine->scheduled_date->copy()->startOfDay();

                    if (!$vaccine->child || $due->gt($today->copy()->addDays($lead))) {
                        continue;
                    }

                    $queued += (int) $this->claim($vaccine->child->user_id, self::VACCINE, 'vaccine', $vaccine->id, $due->toDateString());
                }
            });

        return $queued;
    }

    /** Doses whose time passed within the last few minutes, today (pharmacy time). */
    public function queueMedicationReminders(?Carbon $now = null): int
    {
        if (!Setting::current()->medication_reminders_enabled) {
            return 0;
        }

        $now = ($now ?? StoreClock::now())->copy();
        $from = $now->copy()->subMinutes(self::MEDICATION_WINDOW_MINUTES);
        $day = Str::lower($now->englishDayOfWeek);
        $queued = 0;

        Medication::with('times', 'days')->whereHas('times')->chunkById(500, function ($medications) use ($now, $from, $day, &$queued) {
            foreach ($medications as $medication) {
                if (!$this->takenOn($medication, $day)) {
                    continue;
                }

                foreach ($medication->times as $time) {
                    $at = $now->copy()->setTimeFromTimeString((string) $time->getRawOriginal('time'));

                    if ($at->gt($from) && $at->lte($now)) {
                        $queued += (int) $this->claim($medication->user_id, self::MEDICATION, 'medication_time', $time->id, $at->format('Y-m-d H:i'));
                    }
                }
            }
        });

        return $queued;
    }

    /** Same day rules as the medication schedule shown in the app. */
    private function takenOn(Medication $medication, string $day): bool
    {
        return match ($medication->repeat_type) {
            'every_day' => true,
            'specific_days' => $medication->days->contains(fn ($d) => Str::lower($d->day) === $day),
            'once' => Str::lower((string) $medication->days->first()?->day) === $day,
            default => false,
        };
    }

    /**
     * Reminders whose job was lost (still "sending" long after it was queued) or whose worker died
     * mid-send ("delivering" past the claim timeout) are queued again; the job's claim decides who
     * sends. One whose attempts are used up is recorded as failed. Returns how many were requeued.
     */
    public function recoverStale(): int
    {
        $cutoff = now()->subMinutes(NotificationDelivery::CLAIM_TIMEOUT_MINUTES);

        NotificationDelivery::where('status', NotificationDelivery::STATUS_DELIVERING)->where('claimed_at', '<', $cutoff)
            ->where('attempts', '>=', NotificationDelivery::MAX_ATTEMPTS)
            ->update(['status' => NotificationDelivery::STATUS_FAILED, 'error' => 'No worker finished sending it', 'updated_at' => now()]);

        $ids = NotificationDelivery::where('attempts', '<', NotificationDelivery::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', NotificationDelivery::STATUS_SENDING)->where('updated_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', NotificationDelivery::STATUS_DELIVERING)->where('claimed_at', '<', $cutoff)))
            ->orderBy('id')->limit(500)->pluck('id');

        foreach ($ids as $id) {
            NotificationDelivery::whereKey($id)->where('status', NotificationDelivery::STATUS_SENDING)->update(['updated_at' => now()]);
            SendHealthReminder::dispatch($id);
        }

        return $ids->count();
    }

    private function claim(int $userId, string $type, string $referenceType, int $referenceId, string $occurrence): bool
    {
        try {
            // A savepoint, so a duplicate does not abort an enclosing transaction.
            $delivery = DB::transaction(fn () => NotificationDelivery::create([
                'user_id' => $userId,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurrence' => $occurrence,
                'status' => NotificationDelivery::STATUS_SENDING,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        SendHealthReminder::dispatch($delivery->id);

        return true;
    }
}
