@extends('admin.layout')

@section('title', 'Delivery zones')
@section('pretitle', 'Delivery')
@section('subtitle', 'Which cities and areas you deliver to, and at what price. Checkout picks the zone of the customer\'s city (or area) and its active rate with the highest priority.')

@section('actions')
    <a href="{{ route('dashboard.cities.index') }}" class="btn btn-outline-secondary"><i class="ti ti-map-2 me-1"></i>Cities &amp; areas</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createZone"><i class="ti ti-plus me-1"></i>New zone</button>
@endsection

@section('content')
@if ($zones->isEmpty())
    <div class="card"><x-admin.empty icon="truck-off" title="No delivery zones yet" text="Without a zone covering the customer's city, checkout to an address is refused." /></div>
@endif

<div class="row row-cards">
    @foreach ($zones as $zone)
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ $zone->name }} <x-admin.status :status="$zone->is_active ? 'active' : 'inactive'" class="ms-1" /></h3>
                        <div class="text-secondary small">
                            {{ $zone->locations->map(fn ($l) => $l->area ? ($l->city?->name.' / '.$l->area->name) : $l->city?->name)->join(', ') ?: 'No locations' }}</div>
                    </div>
                    <div class="card-actions btn-list">
                        <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editZone{{ $zone->id }}" title="Edit zone" aria-label="Edit zone"><i class="ti ti-edit"></i></button>
                        <x-admin.delete :action="route('dashboard.delivery.zones.destroy', $zone)" confirm="Delete this zone and its rates? Past orders keep the fee they paid." />
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Fee</th><th>Free over</th><th>Min. order</th><th>ETA</th><th>Priority</th><th>Provider</th><th class="w-1"></th></tr></thead>
                        <tbody>
                        @forelse ($zone->rates->sortByDesc('priority') as $rate)
                            <tr class="{{ $rate->is_active ? '' : 'text-secondary' }}">
                                <td><x-admin.money :amount="$rate->fee" />@unless ($rate->is_active)<span class="badge bg-secondary-lt ms-1">off</span>@endunless</td>
                                <td>{{ $rate->free_over_amount !== null ? number_format((float) $rate->free_over_amount, 2) : '—' }}</td>
                                <td>{{ $rate->min_order_amount !== null ? number_format((float) $rate->min_order_amount, 2) : '—' }}</td>
                                <td>{{ $rate->eta_min_days !== null ? $rate->eta_min_days.'–'.($rate->eta_max_days ?? $rate->eta_min_days).' d' : '—' }}</td>
                                <td>{{ $rate->priority }}</td>
                                <td class="small">{{ $rate->provider?->name ?? 'Default' }}
                                    @if ($rate->starts_at || $rate->ends_at)<div class="text-secondary">{{ $rate->starts_at?->format('M j') ?? '…' }} → {{ $rate->ends_at?->format('M j') ?? '…' }}</div>@endif</td>
                                <td>
                                    <div class="btn-list flex-nowrap">
                                        <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editRate{{ $rate->id }}" title="Edit rate" aria-label="Edit rate"><i class="ti ti-edit"></i></button>
                                        <x-admin.delete :action="route('dashboard.delivery.rates.destroy', $rate)" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-danger small">No rate: checkout cannot deliver to this zone.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createRate{{ $zone->id }}"><i class="ti ti-plus me-1"></i>Add rate</button>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection

@push('modals')
    @foreach ([null, ...$zones] as $zone)
        @php $editing = $zone !== null; $selectedCities = $editing ? $zone->locations->whereNull('area_id')->pluck('city_id')->all() : []; $selectedAreas = $editing ? $zone->locations->whereNotNull('area_id')->pluck('area_id')->all() : []; @endphp
        <x-admin.modal :id="$editing ? 'editZone'.$zone->id : 'createZone'" :title="$editing ? 'Edit zone '.$zone->name : 'New delivery zone'"
                       :action="$editing ? route('dashboard.delivery.zones.update', $zone) : route('dashboard.delivery.zones.store')"
                       :method="$editing ? 'PUT' : 'POST'" size="lg">
            <div class="row">
                <x-admin.input col="col-12 mb-3" name="name" label="Name" :value="$zone?->name" required maxlength="255" />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Whole cities</label>
                    <select name="city_ids[]" class="form-select" multiple size="8">
                        @foreach ($cities as $city)
                            <option value="{{ $city->id }}" @selected(in_array($city->id, $selectedCities))>{{ $city->name }}</option>
                        @endforeach
                    </select>
                    <small class="form-hint">Each city or area can be in one zone only.</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Or specific areas</label>
                    <select name="area_ids[]" class="form-select" multiple size="8">
                        @foreach ($cities as $city)
                            @if ($city->areas->isNotEmpty())
                                <optgroup label="{{ $city->name }}">
                                    @foreach ($city->areas as $area)
                                        <option value="{{ $area->id }}" @selected(in_array($area->id, $selectedAreas))>{{ $area->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    <small class="form-hint">An area zone wins over the zone of its whole city.</small>
                </div>
            </div>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($zone?->is_active ?? true)>
                <span class="form-check-label">Active</span>
            </label>
        </x-admin.modal>

        @if ($editing)
            @foreach ([null, ...$zone->rates] as $rate)
                <x-admin.modal :id="$rate ? 'editRate'.$rate->id : 'createRate'.$zone->id" :title="($rate ? 'Edit rate' : 'New rate').' · '.$zone->name"
                               :action="$rate ? route('dashboard.delivery.rates.update', $rate) : route('dashboard.delivery.rates.store', $zone)"
                               :method="$rate ? 'PUT' : 'POST'" size="lg">
                    <div class="row">
                        <x-admin.input col="col-md-4 mb-3" type="number" name="fee" :label="'Fee ('.config('pharmacy.currency').')'" :value="$rate?->fee" step="0.01" min="0" required />
                        <x-admin.input col="col-md-4 mb-3" type="number" name="free_over_amount" label="Free delivery over" :value="$rate?->free_over_amount" step="0.01" min="0" hint="Items subtotal. Empty = never free." />
                        <x-admin.input col="col-md-4 mb-3" type="number" name="min_order_amount" label="Minimum order" :value="$rate?->min_order_amount" step="0.01" min="0" hint="Empty = no minimum." />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="eta_min_days" label="ETA from (days)" :value="$rate?->eta_min_days" min="0" />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="eta_max_days" label="ETA to (days)" :value="$rate?->eta_max_days" min="0" />
                        <x-admin.input col="col-md-3 mb-3" type="number" name="priority" label="Priority" :value="$rate?->priority ?? 0" hint="Highest active wins." />
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Provider</label>
                            <select name="delivery_provider_id" class="form-select">
                                <option value="">Default provider</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected($rate?->delivery_provider_id == $provider->id)>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="starts_at" label="Effective from" :value="$rate?->starts_at?->format('Y-m-d\TH:i')" hint="Store time. Empty = now." />
                        <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="ends_at" label="Effective until" :value="$rate?->ends_at?->format('Y-m-d\TH:i')" hint="Empty = no end." />
                    </div>
                    <label class="form-check form-switch mb-0">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($rate?->is_active ?? true)>
                        <span class="form-check-label">Active</span>
                    </label>
                </x-admin.modal>
            @endforeach
        @endif
    @endforeach
@endpush
