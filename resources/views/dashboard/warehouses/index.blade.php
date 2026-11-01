@extends('admin.layout')

@section('title', 'Warehouses')
@section('pretitle', 'Inventory')
@section('subtitle', 'Sellable warehouses feed the stock customers can buy. A warehouse holding stock cannot be deactivated.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#warehouseNew"><i class="ti ti-plus me-1"></i>New warehouse</button>
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Code</th><th>Name</th><th class="text-end">SKUs</th><th class="text-end">On hand</th><th>Locations</th><th>Flags</th><th class="w-1"></th></tr></thead>
            <tbody>
            @foreach ($warehouses as $w)
                <tr>
                    <td class="fw-medium">{{ $w->code }}</td>
                    <td>{{ $w->name }}<div class="small text-secondary">{{ $w->address }}</div></td>
                    <td class="text-end">{{ $w->balances_count }}</td>
                    <td class="text-end">{{ number_format((int) $w->balances_sum_on_hand) }}</td>
                    <td class="small">{{ $w->locations->pluck('code')->implode(', ') ?: '—' }}</td>
                    <td>
                        @if ($w->is_default)<span class="badge bg-blue-lt">default</span>@endif
                        <x-admin.status :status="$w->is_active ? 'active' : 'inactive'" />
                        @unless ($w->is_sellable)<span class="badge bg-secondary-lt">not sellable</span>@endunless
                    </td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#warehouse{{ $w->id }}" aria-label="Edit"><i class="ti ti-edit"></i></button>
                        <button class="btn btn-sm btn-ghost-secondary btn-icon" data-bs-toggle="modal" data-bs-target="#location{{ $w->id }}" aria-label="Add location"><i class="ti ti-map-pin-plus"></i></button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('modals')
    @foreach ([null, ...$warehouses] as $w)
        <x-admin.modal :id="$w ? 'warehouse'.$w->id : 'warehouseNew'" :title="$w ? 'Edit '.$w->code : 'New warehouse'"
                       :action="$w ? route('dashboard.warehouses.update', $w) : route('dashboard.warehouses.store')" :method="$w ? 'PUT' : 'POST'">
            <div class="row">
                <x-admin.input col="col-md-4 mb-3" name="code" label="Code" :value="$w?->code" required maxlength="30" />
                <x-admin.input col="col-md-8 mb-3" name="name" label="Name" :value="$w?->name" required />
            </div>
            <x-admin.input name="address" label="Address" :value="$w?->address" />
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="phone" label="Phone" :value="$w?->phone" />
            </div>
            <label class="form-check"><input type="checkbox" class="form-check-input" name="is_sellable" value="1" @checked($w?->is_sellable ?? true)><span class="form-check-label">Sellable online (counted in the app's stock)</span></label>
            <label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($w?->is_active ?? true)><span class="form-check-label">Active</span></label>
            <label class="form-check"><input type="checkbox" class="form-check-input" name="is_default" value="1" @checked($w?->is_default ?? false)><span class="form-check-label">Default (opening stock, legacy operations)</span></label>
        </x-admin.modal>
        @if ($w)
            <x-admin.modal :id="'location'.$w->id" :title="'New location in '.$w->code" :action="route('dashboard.warehouses.locations.store', $w)">
                <x-admin.input name="code" label="Location code" required maxlength="30" hint="Aisle / shelf / bin, e.g. A-01-3" />
                <x-admin.input name="name" label="Description" />
            </x-admin.modal>
        @endif
    @endforeach
@endpush
