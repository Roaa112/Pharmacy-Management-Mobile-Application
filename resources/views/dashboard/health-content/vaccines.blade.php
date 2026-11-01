@extends('admin.layout')

@section('title', 'Vaccination schedule')
@section('pretitle', 'Health content')
@section('subtitle', 'The schedule given to each child added in the app. Changes apply to children added from now on: existing children keep the schedule they received. This is medical information; every change is recorded in the audit log.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createVaccine"><i class="ti ti-plus me-1"></i>New vaccine</button>
@endsection

@section('content')
<div class="card">
    @if ($items->isEmpty())
        <x-admin.empty icon="vaccine" title="The schedule is empty" text="Children added now get the built-in default schedule." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>#</th><th>Vaccine</th><th>Code</th><th>Age</th><th>Reminder</th><th>Version</th><th>Given to</th><th>Status</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($items as $item)
                    <tr class="{{ $item->trashed() ? 'text-secondary' : '' }}">
                        <td>{{ $item->sort_order }}</td>
                        <td><div class="fw-medium">{{ $item->name_en }}</div><div class="small" dir="rtl">{{ $item->name_ar }}</div></td>
                        <td><code>{{ $item->code }}</code></td>
                        <td>{{ $item->offset_value }} {{ $item->offset_unit }}{{ $item->offset_value == 1 ? '' : 's' }}</td>
                        <td>{{ $item->reminder_days_before }} days before</td>
                        <td>v{{ $item->version }}</td>
                        <td>{{ $usage[$item->id] ?? 0 }} children</td>
                        <td>@if ($item->trashed())<span class="badge bg-secondary-lt">Removed</span>@else<x-admin.status :status="$item->is_active ? 'active' : 'inactive'" />@endif</td>
                        <td class="text-nowrap">
                            @unless ($item->trashed())
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editVaccine{{ $item->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.health_content.vaccines.destroy', $item)" confirm="Remove this vaccine from the schedule for new children? Children who already have it keep it." />
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('modals')
    @foreach ([null, ...$items->reject->trashed()] as $item)
        @php $editing = $item !== null; @endphp
        <x-admin.modal :id="$editing ? 'editVaccine'.$item->id : 'createVaccine'" :title="$editing ? 'Edit '.$item->name_en : 'New vaccine'"
                       :action="$editing ? route('dashboard.health_content.vaccines.update', $item) : route('dashboard.health_content.vaccines.store')" :method="$editing ? 'PUT' : 'POST'" size="lg">
            <div class="row">
                <x-admin.input col="col-md-4 mb-3" name="code" label="Code" :value="$item?->code" required pattern="[A-Za-z0-9_-]+" />
                <x-admin.input col="col-md-4 mb-3" type="number" name="sort_order" label="Order" :value="$item?->sort_order ?? ($items->max('sort_order') + 1)" min="0" />
                <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$item?->name_en" required />
                <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$item?->name_ar" required dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_en" label="Description (English)" :value="$item?->description_en" rows="3" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_ar" label="Description (Arabic)" :value="$item?->description_ar" rows="3" dir="rtl" />
                <x-admin.input col="col-md-4 mb-3" type="number" name="offset_value" label="Given at age" :value="$item?->offset_value ?? 0" min="0" required />
                <div class="col-md-4 mb-3">
                    <label class="form-label required">Unit</label>
                    <select name="offset_unit" class="form-select">
                        @foreach (\App\Models\VaccineScheduleItem::UNITS as $unit)<option value="{{ $unit }}" @selected(($item?->offset_unit ?? 'month') === $unit)>{{ $unit }}s</option>@endforeach
                    </select>
                </div>
                <x-admin.input col="col-md-4 mb-3" type="number" name="reminder_days_before" label="Remind (days before)" :value="$item?->reminder_days_before ?? 3" min="0" max="60" required />
            </div>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($item?->is_active ?? true)>
                <span class="form-check-label">Active (given to new children)</span>
            </label>
        </x-admin.modal>
    @endforeach
@endpush
