@extends('admin.layout')

@section('title', 'Refund '.$refund->reference)
@section('pretitle', 'Refunds · order #'.$refund->order_id)

@section('actions')
    <a href="{{ route('dashboard.refunds.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Refunds</a>
    <a href="{{ route('dashboard.orders.show', $refund->order_id) }}" class="btn btn-outline-primary">Order #{{ $refund->order_id }}</a>
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Details</h3><div class="card-actions"><x-admin.status :status="$refund->status" /></div></div>
            <table class="table card-table">
                <tr><td>Amount</td><td><x-admin.money :amount="$refund->amount" /> {{ $refund->currency }}</td></tr>
                <tr><td>Method</td><td>{{ ['gateway' => 'Through the payment provider', 'cash' => 'Cash paid back', 'manual' => 'Manual (transfer, voucher…)'][$refund->method] ?? $refund->method }}</td></tr>
                <tr><td>Payment</td><td>@if ($refund->payment)<a href="{{ route('dashboard.payments.show', $refund->payment) }}">{{ $refund->payment->internal_reference }}</a>@else — @endif</td></tr>
                <tr><td>Return</td><td>@if ($refund->returnRequest)<a href="{{ route('dashboard.returns.show', $refund->return_request_id) }}">#{{ $refund->return_request_id }}</a>@else — @endif</td></tr>
                <tr><td>Reason</td><td>{{ $refund->reason }}</td></tr>
                <tr><td>Requested</td><td>{{ $refund->created_at->format('Y-m-d H:i') }} {{ $refund->requester?->name }}</td></tr>
                <tr><td>Processed</td><td>{{ $refund->processed_at?->format('Y-m-d H:i') ?? '—' }} {{ $refund->processor?->name }}</td></tr>
                <tr><td>Provider refund</td><td class="small">{{ $refund->provider_refund_id ?? '—' }}</td></tr>
                <tr><td>Reference</td><td>{{ $refund->external_reference ?? '—' }}</td></tr>
                @if ($refund->failure_reason)<tr><td>Failure / note</td><td class="text-danger">{{ $refund->failure_reason }}</td></tr>@endif
                @if (!empty($refund->metadata['backfilled']))<tr><td colspan="2" class="small text-secondary">Recorded from an accepted return before refunds were tracked.</td></tr>@endif
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        @if (in_array($refund->status, ['pending', 'failed'], true))
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Pay back</h3></div>
                <form method="POST" action="{{ route('dashboard.refunds.process', $refund) }}" class="card-body" onsubmit="return confirm('Refund {{ number_format((float) $refund->amount, 2) }} now?')">
                    @csrf
                    @if ($refund->method === 'gateway')
                        <p class="text-secondary small">The refund is sent to the payment provider. It completes when the provider confirms it.</p>
                    @else
                        <x-admin.input name="external_reference" label="Reference" :required="$refund->method === 'manual'" hint="Cash voucher, transfer number… recorded with the refund." />
                    @endif
                    <button class="btn btn-danger w-100">{{ $refund->method === 'gateway' ? 'Refund through the provider' : 'Record refund as paid' }}</button>
                </form>
            </div>
            <div class="card">
                <form method="POST" action="{{ route('dashboard.refunds.cancel', $refund) }}" class="card-body">
                    @csrf
                    <x-admin.input name="reason" label="Cancel this refund — reason" required />
                    <button class="btn btn-outline-secondary w-100">Cancel refund</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
