@extends('admin.layout')

@section('title', 'Health settings')
@section('pretitle', 'Health content')
@section('subtitle', 'Which health tools the app offers, what is shown with every result, ovulation parameters and reminders. Changes apply from now on and are recorded in the audit log.')

@php
    use App\Modules\HealthService\Services\HealthSettings;
    $canManage = auth('admin')->user()?->can('health_content.manage');
    $toolLabels = [
        'bmi' => ['Body weight & BMI', 'POST /health/body-weight'],
        'blood_sugar' => ['Blood sugar', 'POST /health/blood-sugar'],
        'blood_pressure' => ['Blood pressure', 'POST /health/blood-pressure'],
        'ovulation' => ['Ovulation calculator', 'POST /health/ovulation'],
        'pregnancy' => ['Pregnancy calculator', 'POST /health/calculatePregnancy'],
        'vaccines' => ['Children & vaccinations', 'POST /health/child'],
        'medication' => ['Medication reminders (adding / editing medications)', 'POST /medication'],
    ];
    $phaseLabels = [
        'menstruation' => 'Period', 'not_fertile' => 'Not fertile', 'fertile_low' => '5 days before ovulation',
        'fertile_medium' => '4–3 days before', 'fertile_high' => '2–1 days before', 'ovulation' => 'Ovulation day',
        'fertile_after' => '1–2 days after', 'post_ovulation' => 'After ovulation',
    ];
    $guidelineLabels = ['bmi' => 'BMI', 'blood_sugar' => 'Blood sugar', 'blood_pressure' => 'Blood pressure', 'ovulation' => 'Ovulation', 'pregnancy' => 'Pregnancy'];
    $s = $settings->setting();
@endphp

@section('content')
<form method="POST" action="{{ route('dashboard.health_content.settings.update') }}">
    @csrf @method('PUT')
    <fieldset @disabled(!$canManage)>
    <div class="row row-cards">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-apps me-2 text-secondary"></i>Health tools in the app</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">A tool switched off accepts no new entries (the app receives <code>403 health_tool_disabled</code>); customers can still see and delete what they saved. The app can read the switches from <code>GET /api/v1/user/health/config</code>.</p>
                    @foreach ($toolLabels as $tool => [$label, $endpoint])
                        <label class="form-check form-switch">
                            <input type="hidden" name="tools[{{ $tool }}]" value="0">
                            <input class="form-check-input" type="checkbox" name="tools[{{ $tool }}]" value="1" @checked(old("tools.{$tool}", $h['tools'][$tool] ?? true))>
                            <span class="form-check-label">{{ $label }} <span class="text-secondary small">({{ $endpoint }})</span></span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-bell me-2 text-secondary"></i>Reminders</h3></div>
                <div class="card-body">
                    <label class="form-check form-switch">
                        <input type="hidden" name="vaccine_reminders_enabled" value="0">
                        <input class="form-check-input" type="checkbox" name="vaccine_reminders_enabled" value="1" @checked(old('vaccine_reminders_enabled', $s->vaccine_reminders_enabled))>
                        <span class="form-check-label">Vaccination reminders</span>
                    </label>
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="time" name="vaccine_reminder_time" label="Send at (store time)" :value="old('vaccine_reminder_time', $h['vaccine_reminder_time'])" required hint="Reminders due today go out from this time." />
                        <x-admin.input col="col-md-6 mb-3" type="number" name="vaccine_default_lead_days" label="Days before (default)" :value="old('vaccine_default_lead_days', $h['vaccine_default_lead_days'])" min="0" max="60" required hint="For vaccines without their own reminder days." />
                    </div>
                    <label class="form-check form-switch">
                        <input type="hidden" name="medication_reminders_enabled" value="0">
                        <input class="form-check-input" type="checkbox" name="medication_reminders_enabled" value="1" @checked(old('medication_reminders_enabled', $s->medication_reminders_enabled))>
                        <span class="form-check-label">Medication dose reminders</span>
                    </label>
                    <x-admin.input type="number" name="medication_window_minutes" label="Remind for doses due in the last (minutes)" :value="old('medication_window_minutes', $h['medication_window_minutes'])" min="5" max="120" required hint="Checked every 5 minutes; a dose is reminded once." />
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-info-circle me-2 text-secondary"></i>Shown with every result</h3></div>
                <div class="card-body">
                    <x-admin.input type="textarea" name="disclaimer_en" label="Disclaimer (English)" :value="old('disclaimer_en', $h['disclaimer_en'])" rows="2" hint="Sent as disclaimer with BMI, blood sugar, blood pressure, ovulation and pregnancy results. Empty = none." />
                    <x-admin.input type="textarea" name="disclaimer_ar" label="Disclaimer (Arabic)" :value="old('disclaimer_ar', $h['disclaimer_ar'])" rows="2" dir="rtl" />
                    <div class="row">
                        @foreach ($guidelineLabels as $key => $label)
                            <x-admin.input col="col-md-6 mb-3" :name="'guidelines['.$key.']'" :label="'Guideline name — '.$label" :value="old('guidelines.'.$key, $h['guidelines'][$key] ?? '')" />
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-scale me-2 text-secondary"></i>BMI</h3></div>
                <div class="card-body">
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="number" step="0.1" name="bmi_ideal_min" label="Healthy BMI from" :value="old('bmi_ideal_min', $h['bmi_ideal_min'])" required />
                        <x-admin.input col="col-md-6 mb-3" type="number" step="0.1" name="bmi_ideal_max" label="Healthy BMI to" :value="old('bmi_ideal_max', $h['bmi_ideal_max'])" required />
                    </div>
                    <small class="form-hint">Used for the "ideal weight" range. BMI categories and their messages are on <a href="{{ route('dashboard.health_content.rules.index') }}">Result rules & messages</a>.</small>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-calendar-heart me-2 text-secondary"></i>Ovulation calculator</h3></div>
                <div class="card-body">
                    <div class="row">
                        <x-admin.input col="col-md-3 mb-3" type="number" name="cycle_length_min" label="Cycle from (days)" :value="old('cycle_length_min', $h['cycle_length_min'])" min="15" max="60" required />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="cycle_length_max" label="Cycle to (days)" :value="old('cycle_length_max', $h['cycle_length_max'])" min="15" max="60" required />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="period_length_min" label="Period from (days)" :value="old('period_length_min', $h['period_length_min'])" min="1" max="15" required />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="period_length_max" label="Period to (days)" :value="old('period_length_max', $h['period_length_max'])" min="1" max="15" required />
                        <x-admin.input col="col-md-4 mb-3" type="number" name="luteal_phase_days" label="Luteal phase (days)" :value="old('luteal_phase_days', $h['luteal_phase_days'])" min="10" max="16" required hint="Ovulation = cycle length − this." />
                    </div>
                    <label class="form-label">Pregnancy chance per phase (%)</label>
                    <div class="row">
                        @foreach (HealthSettings::PHASES as $phase)
                            <x-admin.input col="col-md-3 col-6 mb-2" type="number" :name="'phase_chances['.$phase.']'" :label="$phaseLabels[$phase]" :value="old('phase_chances.'.$phase, $h['phase_chances'][$phase] ?? 0)" min="0" max="100" required />
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    </fieldset>

    @if ($canManage)
        <div class="mt-3"><button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save health settings</button></div>
    @endif
</form>
@endsection
