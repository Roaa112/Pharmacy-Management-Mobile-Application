@extends('admin.layout')

@section('title', 'Shipments')
@section('pretitle', 'Delivery')
@section('subtitle', 'Every delivery, whoever carries it. Failed or returned shipments and unknown provider statuses are flagged for attention.')

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-4"><x-admin.stat label="Cash collected, not yet settled" icon="cash" tone="orange" :value="number_format($codUnsettled, 2)" /></div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex flex-wrap gap-2 w-100">
            <select name="status" class="form-select w-auto" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', $status) }}</option>@endforeach
            </select>
            <label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="attention" value="1" @checked(request()->boolean('attention'))><span class="form-check-label">Needs attention</span></label>
            <label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="cod_unsettled" value="1" @checked(request()->boolean('cod_unsettled'))><span class="form-check-label">Cash not settled</span></label>
            <button class="btn btn-outline-primary ms-auto">Filter</button>
        </form>
    </div>
    @if ($shipments->isEmpty())
        <x-admin.empty icon="truck" title="No shipments" text="Shipments are created at checkout for orders delivered to an address." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Provider</th><th>Tracking</th><th>Status</th><th class="text-end">COD</th><th>Updated</th></tr></thead>
                <tbody>
                @foreach ($shipments as $shipment)
                    <tr>
                        <td><a href="{{ route('dashboard.orders.show', $shipment->order_id) }}">#{{ $shipment->order_id }}</a></td>
                        <td>{{ $shipment->order?->user?->name ?? '—' }}</td>
                        <td>{{ $shipment->provider?->name ?? '—' }}</td>
                        <td class="small">{{ $shipment->tracking_number ?? '—' }}</td>
                        <td><x-admin.status :status="$shipment->status->value" />@if ($shipment->needs_attention)<i class="ti ti-alert-triangle text-orange ms-1" title="Needs attention"></i>@endif</td>
                        <td class="text-end small">
                            @if ((float) $shipment->cod_amount > 0)
                                <x-admin.money :amount="$shipment->cod_amount" />
                                <div class="text-secondary">{{ $shipment->cod_settled_at ? 'settled' : ($shipment->cod_collected_at ? 'collected' : 'due') }}</div>
                            @else — @endif
                        </td>
                        <td class="small text-secondary">{{ $shipment->updated_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$shipments" />
    @endif
</div>
@endsection
