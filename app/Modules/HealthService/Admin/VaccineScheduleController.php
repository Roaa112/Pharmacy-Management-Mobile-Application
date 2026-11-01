<?php

namespace App\Modules\HealthService\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Country;
use App\Models\Vaccine;
use App\Models\VaccineScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The vaccination schedule template (health_content permission). Changes apply to children
 * added from now on; existing children keep the schedule they were given (each vaccine row is a
 * snapshot that records the template version). Every change is in the audit log.
 */
class VaccineScheduleController extends Controller
{
    public function index()
    {
        return view('dashboard.health-content.vaccines', [
            'items' => VaccineScheduleItem::withTrashed()->orderBy('sort_order')->get(),
            'usage' => Vaccine::selectRaw('vaccine_schedule_item_id, COUNT(*) as total')->whereNotNull('vaccine_schedule_item_id')
                ->groupBy('vaccine_schedule_item_id')->pluck('total', 'vaccine_schedule_item_id'),
        ]);
    }

    public function store(Request $request)
    {
        $item = VaccineScheduleItem::create($this->validated($request));
        AdminAuditLog::record('health_content.vaccine.create', $item, $item->toArray());

        return redirect()->back()->with('success', 'Vaccine added to the schedule. It applies to children added from now on.');
    }

    public function update(Request $request, VaccineScheduleItem $item)
    {
        $before = $item->only([...VaccineScheduleItem::VERSIONED, 'is_active', 'sort_order']);
        $item->update($this->validated($request, $item));
        AdminAuditLog::record('health_content.vaccine.update', $item, ['before' => $before, 'after' => $item->getChanges()]);

        return redirect()->back()->with('success', "Saved (version {$item->version}). Existing children keep their schedule.");
    }

    /** Soft delete: children who received this entry keep their copy. */
    public function destroy(VaccineScheduleItem $item)
    {
        $item->delete();
        AdminAuditLog::record('health_content.vaccine.delete', $item, ['code' => $item->code]);

        return redirect()->back()->with('success', 'Vaccine removed from the schedule for new children.');
    }

    private function validated(Request $request, ?VaccineScheduleItem $item = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('vaccine_schedule_items', 'code')->whereNull('deleted_at')->ignore($item)],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:2000',
            'description_en' => 'nullable|string|max:2000',
            'offset_value' => 'required|integer|min:0|max:'.($request->input('offset_unit') === 'day' ? 6600 : 216),
            'offset_unit' => ['required', Rule::in(VaccineScheduleItem::UNITS)],
            'reminder_days_before' => 'required|integer|min:0|max:60',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        // An 18-year limit whatever the unit (children's schedule).
        $data['is_active'] = $request->boolean('is_active');
        $data['country_id'] = Country::default()?->id;
        $data['sort_order'] = (int) $request->input('sort_order', 0);

        return $data;
    }
}
