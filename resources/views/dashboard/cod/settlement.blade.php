@extends('admin.layout')

@section('title', 'Settlement '.$settlement->reference)
@section('pretitle', 'Cash on delivery')

@section('actions')
    <a href="{{ route('dashboard.cod.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>COD</a>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-3"><x-admin.stat label="Expected" icon="calculator" :value="number_format((float) $settlement->expected_amount, 2)" /></div>
    <div class="col-md-3"><x-admin.stat label="Received" icon="cash" tone="teal" :value="number_format((float) $settlement->received_amount, 2)" /></div>
    <div class="col-md-3"><x-admin.stat label="Difference" icon="scale" :tone="(float) $settlement->difference == 0 ? 'green' : 'red'" :value="number_format((float) $settlement->difference, 2)" /></div>
    <div class="col-md-3"><x-admin.stat label="Courier" icon="truck" tone="blue" :value="$settlement->provider?->name ?? 'Counter'" :hint="$settlement->settled_at->format('Y-m-d H:i').' · '.($settlement->settler?->name ?? '—')" /></div>
</div>
<div class="card">
    <div class="card-header"><h3 class="card-title">Payments settled</h3>@if ($settlement->received_reference)<div class="card-actions small">Ref. {{ $settlement->received_reference }}</div>@endif</div>
    <table class="table card-table">
        @foreach ($settlement->payments as $p)
            <tr><td><a href="{{ route('dashboard.payments.show', $p) }}">{{ $p->internal_reference }}</a></td><td><a href="{{ route('dashboard.orders.show', $p->order_id) }}">#{{ $p->order_id }}</a></td><td>{{ $p->order?->user?->name }}</td><td class="text-end"><x-admin.money :amount="$p->collected_amount" /></td></tr>
        @endforeach
    </table>
    @if ($settlement->notes)<div class="card-footer small text-secondary">{{ $settlement->notes }}</div>@endif
</div>
@endsection
