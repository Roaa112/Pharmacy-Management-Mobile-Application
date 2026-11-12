<?php

namespace App\Modules\HealthService\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Setting;
use App\Modules\HealthService\Services\HealthSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Health content › Health settings: which tools the app offers, the disclaimer and guideline
 * names shown with results, BMI ideal range, ovulation parameters and reminders.
 */
class HealthSettingsController extends Controller
{
    public function edit()
    {
        $settings = HealthSettings::current();

        return view('dashboard.health-content.settings', ['h' => $settings->all(), 'settings' => $settings]);
    }

    public function update(Request $request)
    {
        $validator = validator($request->all(), [
            'tools' => ['nullable', 'array'],
            'tools.*' => ['nullable', 'boolean'],
            'disclaimer_ar' => ['nullable', 'string', 'max:2000'],
            'disclaimer_en' => ['nullable', 'string', 'max:2000'],
            'guidelines' => ['nullable', 'array'],
            'guidelines.*' => ['nullable', 'string', 'max:255'],
            'bmi_ideal_min' => ['required', 'numeric', 'min:10', 'max:40'],
            'bmi_ideal_max' => ['required', 'numeric', 'gt:bmi_ideal_min', 'max:50'],
            'cycle_length_min' => ['required', 'integer', 'min:15', 'max:60'],
            'cycle_length_max' => ['required', 'integer', 'gte:cycle_length_min', 'max:60'],
            'period_length_min' => ['required', 'integer', 'min:1', 'max:15'],
            'period_length_max' => ['required', 'integer', 'gte:period_length_min', 'max:15'],
            'luteal_phase_days' => ['required', 'integer', 'min:10', 'max:16'],
            'phase_chances' => ['required', 'array'],
            'phase_chances.*' => ['required', 'integer', 'min:0', 'max:100'],
            'vaccine_reminders_enabled' => ['nullable', 'boolean'],
            'vaccine_reminder_time' => ['required', 'date_format:H:i'],
            'vaccine_default_lead_days' => ['required', 'integer', 'min:0', 'max:60'],
            'medication_reminders_enabled' => ['nullable', 'boolean'],
            'medication_window_minutes' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        // Ovulation falls on day (cycle length - luteal phase) of the cycle, so the shortest accepted
        // cycle must be longer than the luteal phase (a period overlapping the fertile window is fine:
        // period days are shown as period).
        $validator->after(function (Validator $validator) use ($request) {
            if ((int) $request->input('cycle_length_min') <= (int) $request->input('luteal_phase_days')) {
                $validator->errors()->add('cycle_length_min', 'The shortest cycle must be longer than the luteal phase.');
            }
        });

        $data = $validator->validate();

        $health = [
            'tools' => collect(HealthSettings::TOOLS)->mapWithKeys(fn ($tool) => [$tool => (bool) ($data['tools'][$tool] ?? false)])->all(),
            'disclaimer_ar' => $data['disclaimer_ar'] ?? null,
            'disclaimer_en' => $data['disclaimer_en'] ?? null,
            'guidelines' => collect(HealthSettings::GUIDELINE_KEYS)->mapWithKeys(fn ($key) => [$key => $data['guidelines'][$key] ?? null])->all(),
            'bmi_ideal_min' => (float) $data['bmi_ideal_min'],
            'bmi_ideal_max' => (float) $data['bmi_ideal_max'],
            'cycle_length_min' => (int) $data['cycle_length_min'],
            'cycle_length_max' => (int) $data['cycle_length_max'],
            'period_length_min' => (int) $data['period_length_min'],
            'period_length_max' => (int) $data['period_length_max'],
            'luteal_phase_days' => (int) $data['luteal_phase_days'],
            'phase_chances' => collect(HealthSettings::PHASES)->mapWithKeys(fn ($phase) => [$phase => (int) ($data['phase_chances'][$phase] ?? 0)])->all(),
            'vaccine_reminder_time' => $data['vaccine_reminder_time'],
            'vaccine_default_lead_days' => (int) $data['vaccine_default_lead_days'],
            'medication_window_minutes' => (int) $data['medication_window_minutes'],
        ];

        $setting = Setting::current();
        $before = ['health_settings' => HealthSettings::current()->all()] + $setting->only(['vaccine_reminders_enabled', 'medication_reminders_enabled']);

        $setting->update([
            'health_settings' => $health,
            'vaccine_reminders_enabled' => $request->boolean('vaccine_reminders_enabled'),
            'medication_reminders_enabled' => $request->boolean('medication_reminders_enabled'),
        ]);

        AdminAuditLog::record('health_settings.update', $setting, [
            'before' => $before,
            'after' => ['health_settings' => $health] + $setting->fresh()->only(['vaccine_reminders_enabled', 'medication_reminders_enabled']),
        ]);

        return redirect()->back()->with('success', 'Health settings saved. They apply from now on.');
    }
}
