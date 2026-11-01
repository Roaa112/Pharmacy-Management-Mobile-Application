@extends('admin.layout')

@section('title', 'Payment methods')
@section('pretitle', 'Payments')
@section('subtitle', 'What customers can choose at checkout: cash on delivery and Paymob. Paymob credentials live in the server environment, never here.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createMethod"><i class="ti ti-plus me-1"></i>New method</button>
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Method</th><th>Code</th><th>Type</th><th>Order</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
            @foreach ($methods as $method)
                <tr class="{{ $method->is_legacy ? 'text-secondary' : '' }}">
                    <td class="fw-medium">{{ $method->name_en }} <span class="text-secondary">{{ $method->name_ar }}</span></td>
                    <td><code>{{ $method->code }}</code></td>
                    <td>{{ ['cod' => 'Cash on delivery', 'paymob' => 'Paymob (online)', 'manual_transfer' => 'Manual transfer'][$method->provider] ?? $method->provider }}
                        @if ($method->is_legacy)<span class="badge bg-secondary-lt ms-1">legacy · history only</span>@endif
                        @if ($method->provider === 'paymob' && !app(\App\Modules\Payments\Services\PaymentService::class)->providerReady('paymob'))<span class="badge bg-orange-lt ms-1">not configured</span>@endif
                    </td>
                    <td>{{ $method->sort_order }}</td>
                    <td><x-admin.status :status="$method->is_active ? 'active' : 'inactive'" /></td>
                    <td>@unless ($method->is_legacy)<button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editMethod{{ $method->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>@endunless</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if ($retired->isNotEmpty())
    {{-- Retired providers are not configurable: listed only so their past orders can be traced. --}}
    <div class="card mt-3">
        <div class="card-header"><div><h3 class="card-title">Retired methods (history only)</h3>
            <div class="card-subtitle">No longer offered or configurable. Their orders, payments and receipts are kept unchanged.</div></div></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table text-secondary">
                <thead><tr><th>Method</th><th>Code</th><th class="text-end">Past orders</th></tr></thead>
                <tbody>
                @foreach ($retired as $method)
                    <tr>
                        <td>{{ $method->name_en }} <span>{{ $method->name_ar }}</span> <span class="badge bg-secondary-lt ms-1">retired</span></td>
                        <td><code>{{ $method->code }}</code></td>
                        <td class="text-end"><a href="{{ route('dashboard.orders.index', ['payment_method' => $method->code]) }}">{{ $retiredOrders[$method->code] ?? 0 }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

@push('modals')
    @foreach ([null, ...$methods] as $method)
        @php $editing = $method !== null; @endphp
        <x-admin.modal :id="$editing ? 'editMethod'.$method->id : 'createMethod'" :title="$editing ? 'Edit '.$method->name_en : 'New payment method'"
                       :action="$editing ? route('dashboard.payment_methods.update', $method) : route('dashboard.payment_methods.store')" :method="$editing ? 'PUT' : 'POST'" size="lg">
            <div class="row">
                @unless ($editing)
                    <x-admin.input col="col-md-6 mb-3" name="code" label="Code (sent by the app)" required pattern="[A-Za-z0-9_-]+" hint="Cannot be changed later." />
                    <div class="col-md-6 mb-3"><label class="form-label required">Type</label>
                        <select name="provider" class="form-select" required><option value="cod">Cash on delivery</option><option value="paymob">Paymob (online)</option></select></div>
                @endunless
                <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$method?->name_en" required />
                <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$method?->name_ar" required dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="instructions_en" label="Instructions (English)" :value="$method?->instructions_en" rows="2" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="instructions_ar" label="Instructions (Arabic)" :value="$method?->instructions_ar" rows="2" dir="rtl" />
                <x-admin.input col="col-md-4 mb-3" type="number" name="sort_order" label="Order" :value="$method?->sort_order ?? 0" min="0" />
            </div>
            <label class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($method?->is_active ?? true)>
                <span class="form-check-label">Active (Paymob can only be activated once it is configured)</span>
            </label>
        </x-admin.modal>
    @endforeach
@endpush
