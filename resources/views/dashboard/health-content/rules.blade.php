@extends('admin.layout')

@section('title', 'Result rules & messages')
@section('pretitle', 'Health content')
@section('subtitle', 'When a BMI, blood sugar or blood pressure reading falls into a category, and what the customer is told. Ranges are "from ≤ value < to"; empty = open. The matching rule with the highest priority wins. Changes apply to readings saved from now on and are recorded in the audit log.')

@php
    use App\Models\HealthResultRule;
    $metricTitles = [HealthResultRule::BMI => 'Body mass index (BMI)', HealthResultRule::BLOOD_SUGAR => 'Blood sugar', HealthResultRule::BLOOD_PRESSURE => 'Blood pressure'];
    $metricIcons = [HealthResultRule::BMI => 'scale', HealthResultRule::BLOOD_SUGAR => 'droplet', HealthResultRule::BLOOD_PRESSURE => 'heart-rate-monitor'];
    $conditionLabels = ['fasting' => 'Fasting / before a meal', 'after_meal' => 'After a meal', 'random' => 'Random'];
    $canManage = auth('admin')->user()?->can('health_rules.manage');
@endphp

@section('content')
@if ($warnings)
    <div class="alert alert-warning">
        <div class="fw-medium mb-1"><i class="ti ti-alert-triangle me-1"></i>Some readings match no rule</div>
        <ul class="mb-0">@foreach ($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
    </div>
@endif

@unless ($canManage)
    <div class="alert alert-info"><i class="ti ti-lock me-1"></i>You can view these rules. Changing medical thresholds and result messages needs the "Manage health result rules" permission.</div>
@endunless

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-flask me-2 text-secondary"></i>Try a reading</h3></div>
    <div class="card-body">
        <form method="GET" action="{{ route('dashboard.health_content.rules.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Measurement</label>
                <select name="try_metric" class="form-select">
                    @foreach ($metricTitles as $metric => $title)<option value="{{ $metric }}" @selected(request('try_metric') === $metric)>{{ $title }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Value / systolic</label><input type="number" step="0.1" min="0" name="try_value" value="{{ request('try_value') }}" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Diastolic (BP)</label><input type="number" step="1" min="0" name="try_value2" value="{{ request('try_value2') }}" class="form-control"></div>
            <div class="col-md-3">
                <label class="form-label">Condition (sugar)</label>
                <select name="try_condition" class="form-select">
                    <option value="">—</option>
                    @foreach ($conditionLabels as $value => $label)<option value="{{ $value }}" @selected(request('try_condition') === $value)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="ti ti-player-play me-1"></i>Check</button></div>
        </form>

        @if ($preview)
            <div class="mt-3 p-3 rounded border">
                @if ($preview['rule'])
                    <div><span class="badge bg-blue-lt me-1">{{ $preview['rule']->code }}</span><strong>{{ $preview['rule']->label_en }}</strong> · <span dir="rtl">{{ $preview['rule']->label_ar }}</span>
                        @if ($preview['rule']->requires_urgent_care)<span class="badge bg-red-lt ms-1">Urgent care</span>@endif
                        @unless ($preview['rule']->exists)<span class="badge bg-secondary-lt ms-1">Guideline default (no active rule)</span>@endunless
                    </div>
                    @if ($preview['rule']->message_en || $preview['rule']->message_ar)
                        <div class="small mt-2">{{ $preview['rule']->message_en }}</div>
                        <div class="small" dir="rtl">{{ $preview['rule']->message_ar }}</div>
                    @else
                        <div class="small text-secondary mt-2">No message for this category.</div>
                    @endif
                @else
                    <span class="badge bg-yellow-lt">unknown</span> No rule covers this reading; the app shows "Unknown".
                @endif
            </div>
        @endif
    </div>
</div>

@foreach ($metricTitles as $metric => $title)
    <div class="card mb-3" id="rules-{{ $metric }}">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-{{ $metricIcons[$metric] }} me-2 text-secondary"></i>{{ $title }} <span class="text-secondary small ms-1">({{ $units[$metric] }})</span></h3>
            @if ($canManage)
                <div class="card-actions d-flex gap-2">
                    <form method="POST" action="{{ route('dashboard.health_content.rules.reset', $metric) }}" data-confirm="Replace every {{ strtolower($title) }} rule with the guideline defaults? Your changes to these rules are lost (the audit log keeps them).">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary"><i class="ti ti-refresh me-1"></i>Reset to guideline defaults</button>
                    </form>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createRule{{ $metric }}"><i class="ti ti-plus me-1"></i>New rule</button>
                </div>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Priority</th><th>Code</th>@if ($metric === HealthResultRule::BLOOD_SUGAR)<th>Condition</th>@endif<th>Applies to</th><th>Label</th><th>Message to the customer</th><th>Status</th><th class="w-1"></th></tr></thead>
                <tbody>
                @forelse ($rules[$metric] ?? [] as $rule)
                    <tr class="{{ $rule->is_active ? '' : 'text-secondary' }}">
                        <td>{{ $rule->priority }}</td>
                        <td><code>{{ $rule->code }}</code>@if ($rule->requires_urgent_care)<div><span class="badge bg-red-lt">Urgent care</span></div>@endif</td>
                        @if ($metric === HealthResultRule::BLOOD_SUGAR)<td>{{ $rule->condition ? $conditionLabels[$rule->condition] : 'Any condition' }}</td>@endif
                        <td class="small">{{ $rule->rangeText() }}</td>
                        <td><div class="fw-medium">{{ $rule->label_en }}</div><div class="small" dir="rtl">{{ $rule->label_ar }}</div></td>
                        <td class="small" style="max-width: 22rem">
                            @if ($rule->message_en || $rule->message_ar)
                                <div>{{ \Illuminate\Support\Str::limit($rule->message_en, 90) }}</div>
                                <div dir="rtl">{{ \Illuminate\Support\Str::limit($rule->message_ar, 90) }}</div>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td><x-admin.status :status="$rule->is_active ? 'active' : 'inactive'" /></td>
                        <td class="text-nowrap">
                            @if ($canManage)
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editRule{{ $rule->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.health_content.rules.destroy', $rule)" confirm="Delete this result rule? Readings it covered will match another rule, or be reported as unknown." />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-admin.empty :icon="$metricIcons[$metric]" title="No rules" text="Readings are classified with the guideline defaults until you add rules or reset them." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endforeach
@endsection

@push('modals')
    @if ($canManage)
        @foreach ([...array_map(fn ($m) => [$m, null], HealthResultRule::METRICS), ...collect($rules)->flatten()->map(fn ($r) => [$r->metric, $r])->all()] as [$metric, $rule])
            @php
                $editing = $rule !== null;
                $bp = $metric === HealthResultRule::BLOOD_PRESSURE;
            @endphp
            <x-admin.modal :id="$editing ? 'editRule'.$rule->id : 'createRule'.$metric" :title="($editing ? 'Edit rule: '.$rule->code : 'New rule').' — '.$metricTitles[$metric]"
                           :action="$editing ? route('dashboard.health_content.rules.update', $rule) : route('dashboard.health_content.rules.store')" :method="$editing ? 'PUT' : 'POST'" size="lg">
                @unless ($editing)<input type="hidden" name="metric" value="{{ $metric }}">@endunless
                <div class="row">
                    <x-admin.input col="col-md-4 mb-3" name="code" label="Code (sent to the app)" :value="$rule?->code" required pattern="[A-Za-z0-9_-]+" hint="e.g. overweight, high, stage_1" />
                    <x-admin.input col="col-md-4 mb-3" type="number" name="priority" label="Priority" :value="$rule?->priority ?? 0" min="-1000" max="1000" required hint="Higher wins when several rules match." />
                    <x-admin.input col="col-md-4 mb-3" type="number" name="sort_order" label="Order (tie-break)" :value="$rule?->sort_order ?? 0" min="0" max="1000" />
                    @if ($metric === HealthResultRule::BLOOD_SUGAR)
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Reading condition</label>
                            <select name="condition" class="form-select">
                                <option value="">Any condition</option>
                                @foreach ($conditionLabels as $value => $label)<option value="{{ $value }}" @selected($rule?->condition === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <x-admin.input col="col-md-3 mb-3" type="number" step="0.01" min="0" name="primary_from" :label="($bp ? 'Systolic' : 'Value').' from (≥)'" :value="$rule?->primary_from" />
                    <x-admin.input col="col-md-3 mb-3" type="number" step="0.01" min="0" name="primary_to" :label="($bp ? 'Systolic' : 'Value').' to (<)'" :value="$rule?->primary_to" />
                    @if ($bp)
                        <x-admin.input col="col-md-3 mb-3" type="number" step="0.01" min="0" name="secondary_from" label="Diastolic from (≥)" :value="$rule?->secondary_from" />
                        <x-admin.input col="col-md-3 mb-3" type="number" step="0.01" min="0" name="secondary_to" label="Diastolic to (<)" :value="$rule?->secondary_to" />
                        <div class="col-12 mb-3"><small class="form-hint">The rule matches when the systolic OR the diastolic value is in its range. Leave every range empty for a catch-all (e.g. "normal" with the lowest priority).</small></div>
                    @endif
                    <x-admin.input col="col-md-6 mb-3" name="label_en" label="Label (English)" :value="$rule?->label_en" required />
                    <x-admin.input col="col-md-6 mb-3" name="label_ar" label="Label (Arabic)" :value="$rule?->label_ar" required dir="rtl" />
                    <x-admin.input col="col-md-6 mb-3" type="textarea" name="message_en" label="Message to the customer (English)" :value="$rule?->message_en" rows="3" hint="Advice shown with the result. Empty = no message." />
                    <x-admin.input col="col-md-6 mb-3" type="textarea" name="message_ar" label="Message to the customer (Arabic)" :value="$rule?->message_ar" rows="3" dir="rtl" />
                    <x-admin.input col="col-md-12 mb-3" name="legacy_ar" label="Arabic text for older app versions (optional)" :value="$rule?->legacy_ar" dir="rtl" hint="Sent in status / evaluation. Empty = the Arabic label." />
                </div>
                <label class="form-check form-switch">
                    <input type="hidden" name="requires_urgent_care" value="0">
                    <input class="form-check-input" type="checkbox" name="requires_urgent_care" value="1" @checked($rule?->requires_urgent_care)>
                    <span class="form-check-label">Requires urgent care (the app is told to alert the customer)</span>
                </label>
                <label class="form-check form-switch mb-0">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($rule?->is_active ?? true)>
                    <span class="form-check-label">Active</span>
                </label>
            </x-admin.modal>
        @endforeach
    @endif
@endpush
