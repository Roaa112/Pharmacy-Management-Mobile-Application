@extends('admin.layout')

@section('title', 'Cities & areas')
@section('pretitle', 'Delivery')
@section('subtitle', 'Governorates (cities) and their areas. Delivery zones are built from these; customer addresses are linked to them.')

@section('actions')
    <x-admin.data-transfer :entities="['cities', 'areas']" />
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCity"><i class="ti ti-plus me-1"></i>New city</button>
@endsection

@section('content')
<div class="card">
    @if ($cities->isEmpty())
        <x-admin.empty icon="map-2" title="No cities for this country" text="Add the governorates you deliver to." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>City</th><th>Arabic / English</th><th>Areas</th><th>Status</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($cities as $city)
                    <tr>
                        <td class="fw-medium">{{ $city->name }}</td>
                        <td class="small">{{ $city->name_ar ?? '—' }} / {{ $city->name_en ?? '—' }}</td>
                        <td class="small">{{ $city->areas->pluck('name')->join(', ') ?: '—' }}</td>
                        <td><x-admin.status :status="$city->is_active ? 'active' : 'inactive'" /></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCity{{ $city->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                            <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#createArea{{ $city->id }}" title="Add area" aria-label="Add area"><i class="ti ti-plus"></i></button>
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
    @foreach ([null, ...$cities] as $city)
        @php $editing = $city !== null; @endphp
        <x-admin.modal :id="$editing ? 'editCity'.$city->id : 'createCity'" :title="$editing ? 'Edit '.$city->name : 'New city'"
                       :action="$editing ? route('dashboard.cities.update', $city) : route('dashboard.cities.store')" :method="$editing ? 'PUT' : 'POST'">
            <x-admin.input name="name" label="Name (as customers type it)" :value="$city?->name" required hint="Older app versions send the governorate as text; it is matched exactly against these names." />
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Arabic name" :value="$city?->name_ar" dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" name="name_en" label="English name" :value="$city?->name_en" />
            </div>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($city?->is_active ?? true)>
                <span class="form-check-label">Active</span>
            </label>
        </x-admin.modal>
        @if ($editing)
            <x-admin.modal :id="'createArea'.$city->id" :title="'New area in '.$city->name" :action="route('dashboard.areas.store', $city)">
                <x-admin.input name="name" label="Name" required />
                <div class="row">
                    <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Arabic name" dir="rtl" />
                    <x-admin.input col="col-md-6 mb-3" name="name_en" label="English name" />
                </div>
            </x-admin.modal>
        @endif
    @endforeach
@endpush
