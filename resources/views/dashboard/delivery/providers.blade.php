@extends('admin.layout')

@section('title', 'Delivery providers')
@section('pretitle', 'Delivery')
@section('subtitle', 'Your own delivery team or external companies. External companies keep their own drivers and tracking: you hand shipments over and receive their status updates.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createProvider"><i class="ti ti-plus me-1"></i>New provider</button>
@endsection

@section('content')
<div class="row row-cards">
    @foreach ($providers as $provider)
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ $provider->name }}
                            <x-admin.status :status="$provider->is_active ? 'active' : 'inactive'" class="ms-1" />
                            @if ($provider->is_default)<span class="badge bg-blue-lt ms-1">Default</span>@endif</h3>
                        <div class="text-secondary small">{{ str_replace('_', ' ', $provider->type) }} · adapter <code>{{ $provider->adapter }}</code> · {{ $provider->shipments_count }} shipments</div>
                    </div>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editProvider{{ $provider->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                    </div>
                </div>
                <div class="card-body small">
                    @if ($provider->adapter !== 'manual')
                        <div class="mb-2">Webhook: <code>{{ url('/api/webhooks/delivery/'.$provider->code) }}</code>
                            @unless ($provider->webhook_secret)<span class="text-danger ms-1">no secret set: updates are refused</span>@endunless</div>
                    @endif
                    <div class="fw-medium mb-1">Status mapping (their status → ours)</div>
                    @forelse ($provider->statusMaps as $map)
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <code>{{ $map->external_status }}</code><i class="ti ti-arrow-right"></i><x-admin.status :status="$map->internal_status" />
                            <x-admin.delete :action="route('dashboard.delivery.statusMaps.destroy', $map)" class="ms-auto" />
                        </div>
                    @empty
                        <div class="text-secondary">None. Statuses that equal ours ({{ implode(', ', $statuses) }}) are understood without a mapping.</div>
                    @endforelse

                    @if (($unmapped[$provider->id] ?? collect())->isNotEmpty())
                        <div class="alert alert-warning mt-3 mb-2 py-2">Received but not mapped (orders were not changed):
                            @foreach ($unmapped[$provider->id] as $row)<code class="ms-1">{{ $row->raw_status }}</code> ×{{ $row->total }}@endforeach</div>
                    @endif

                    <form method="POST" action="{{ route('dashboard.delivery.statusMaps.store', $provider) }}" class="row g-2 mt-2">
                        @csrf
                        <div class="col"><input name="external_status" class="form-control form-control-sm" placeholder="Their status" required maxlength="100" aria-label="Their status"></div>
                        <div class="col">
                            <select name="internal_status" class="form-select form-select-sm" aria-label="Our status">
                                @foreach ($statuses as $status)<option value="{{ $status }}">{{ str_replace('_', ' ', $status) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-primary">Map</button></div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection

@push('modals')
    @foreach ([null, ...$providers] as $provider)
        @php $editing = $provider !== null; @endphp
        <x-admin.modal :id="$editing ? 'editProvider'.$provider->id : 'createProvider'" :title="$editing ? 'Edit '.$provider->name : 'New delivery provider'"
                       :action="$editing ? route('dashboard.delivery.providers.update', $provider) : route('dashboard.delivery.providers.store')"
                       :method="$editing ? 'PUT' : 'POST'" size="lg">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="name" label="Name" :value="$provider?->name" required />
                <x-admin.input col="col-md-6 mb-3" name="code" label="Code (used in the webhook URL)" :value="$provider?->code" required pattern="[A-Za-z0-9_-]+" />
                <div class="col-md-4 mb-3">
                    <label class="form-label required">Type</label>
                    <select name="type" class="form-select">
                        @foreach (\App\Models\DeliveryProvider::TYPES as $type)
                            <option value="{{ $type }}" @selected(($provider?->type ?? 'external_manual') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label required">Integration</label>
                    <select name="adapter" class="form-select">
                        @foreach ($adapters as $adapter)
                            <option value="{{ $adapter }}" @selected(($provider?->adapter ?? 'manual') === $adapter)>{{ $adapter === 'manual' ? 'Manual (dashboard updates)' : 'Signed webhook' }}</option>
                        @endforeach
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="password" name="webhook_secret" label="Webhook secret" autocomplete="new-password" :hint="$editing ? 'Leave empty to keep the current secret.' : 'At least 16 characters; share it with the provider.'" />
                <x-admin.input col="col-md-6 mb-3" name="tracking_url_template" label="Tracking URL" :value="$provider?->tracking_url_template" placeholder="https://track.example.com/{tracking}" hint="{tracking} is replaced by the tracking number." />
            </div>
            @foreach (['supports_cod' => ['Collects cash on delivery', true], 'is_active' => ['Active', true], 'is_default' => ['Default provider for new shipments', false]] as $field => [$label, $default])
                <label class="form-check form-switch">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" @checked($provider?->$field ?? $default)>
                    <span class="form-check-label">{{ $label }}</span>
                </label>
            @endforeach
        </x-admin.modal>
    @endforeach
@endpush
