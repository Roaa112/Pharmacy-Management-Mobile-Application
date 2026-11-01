@extends('admin.layout')

@section('title', 'Payment '.$payment->internal_reference)
@section('pretitle', 'Payments · order #'.$payment->order_id)

@section('actions')
    <a href="{{ route('dashboard.payments.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Payments</a>
    <a href="{{ route('dashboard.orders.show', $payment->order_id) }}" class="btn btn-outline-primary">Order #{{ $payment->order_id }}</a>
    @if ($payment->isOnline() && $payment->provider_transaction_id)
        <form method="POST" action="{{ route('dashboard.payments.refresh', $payment) }}">@csrf<button class="btn btn-outline-primary" title="Ask the provider for the current state"><i class="ti ti-refresh me-1"></i>Verify with provider</button></form>
    @endif
    @can('refunds')
        @if ($payment->refundableMinor() > 0)<button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#refundNew">Refund</button>@endif
    @endcan
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Details</h3><div class="card-actions"><x-admin.status :status="$payment->status->value" /></div></div>
            <table class="table card-table table-sm">
                <tr><td>Method</td><td>{{ $payment->method?->name_en ?? $payment->provider }} @if ($payment->method?->is_legacy)<span class="badge bg-secondary-lt">legacy</span>@endif</td></tr>
                <tr><td>Amount</td><td><x-admin.money :amount="$payment->amount" /> {{ $payment->currency }}</td></tr>
                <tr><td>Attempt</td><td>{{ $payment->attempt }}</td></tr>
                <tr><td>Customer</td><td>{{ $payment->order?->user?->name }} · {{ $payment->order?->user?->phone }}</td></tr>
                @if ($payment->isOnline())
                    <tr><td>Provider order</td><td class="small">{{ $payment->provider_order_id ?? '—' }}</td></tr>
                    <tr><td>Provider transaction</td><td class="small">{{ $payment->provider_transaction_id ?? '—' }}</td></tr>
                    <tr><td>Expires</td><td>{{ $payment->expires_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                @endif
                @if ($payment->isCod())
                    <tr><td>Collection</td><td>{{ $payment->collection_status ?? '—' }} · <x-admin.money :amount="$payment->collected_amount" /> {{ $payment->collected_via ? 'by '.$payment->collected_via : '' }}{{ $payment->collector ? ' ('.$payment->collector->name.')' : '' }}</td></tr>
                    <tr><td>Collected at</td><td>{{ $payment->collected_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                    <tr><td>Settlement</td><td>@if ($payment->settlement)<a href="{{ route('dashboard.cod.settlements.show', $payment->settlement) }}">{{ $payment->settlement->reference }}</a> · @endif{{ $payment->settled_at?->format('Y-m-d H:i') ?? 'not settled' }}</td></tr>
                @endif
                <tr><td>Paid at</td><td>{{ $payment->paid_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                <tr><td>Refunded</td><td><x-admin.money :amount="$payment->refunded_amount" /></td></tr>
                @if ($payment->failure_reason)<tr><td>Failure</td><td class="text-danger">{{ $payment->failure_reason }}</td></tr>@endif
                @if (!empty($payment->metadata['needs_review']))<tr><td>Review</td><td class="text-orange">{{ $payment->metadata['needs_review'] }}</td></tr>@endif
            </table>
            @if ($payment->isCod() && in_array($payment->status->value, ['pending', 'partially_paid'], true))
                <form method="POST" action="{{ route('dashboard.payments.collect', $payment) }}" class="card-footer d-flex gap-1">
                    @csrf
                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="{{ number_format((float) $payment->amount - (float) $payment->collected_amount, 2, '.', '') }}" aria-label="Amount collected">
                    <select name="via" class="form-select w-auto" aria-label="Collected by"><option value="counter">Counter</option><option value="courier">Courier</option></select>
                    <button class="btn btn-success">Record cash</button>
                </form>
            @endif
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Status history</h3></div>
            <div class="list-group list-group-flush">
                @foreach ($payment->history as $h)
                    <div class="list-group-item small"><span class="fw-medium">{{ $h->from_status ?? 'new' }} → {{ $h->to_status }}</span> · {{ $h->actor_type }}{{ $h->actor_id ? ' #'.$h->actor_id : '' }}
                        <div class="text-secondary">{{ $h->created_at?->format('Y-m-d H:i:s') }} {{ $h->note }}</div></div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Events</h3></div>
            <div class="table-responsive">
                <table class="table card-table table-sm">
                    <thead><tr><th>When</th><th>Event</th><th>Signature</th><th>Result</th><th>Details</th></tr></thead>
                    @forelse ($payment->events as $e)
                        <tr>
                            <td class="small text-nowrap">{{ $e->created_at->format('m-d H:i:s') }}</td>
                            <td class="small">{{ $e->event_type }}<div class="text-secondary">{{ $e->provider_event_id }}</div></td>
                            <td>@if ($e->signature_valid === null) — @elseif ($e->signature_valid)<i class="ti ti-check text-green"></i>@else<i class="ti ti-x text-red"></i>@endif</td>
                            <td><span class="badge bg-{{ ['processed' => 'green', 'rejected' => 'red', 'failed' => 'red', 'ignored' => 'secondary', 'duplicate' => 'secondary'][$e->result] ?? 'blue' }}-lt">{{ $e->result }}</span></td>
                            <td class="small">{{ $e->error }}@if ($e->payload)<details><summary class="text-secondary">payload</summary><pre class="small mb-0">{{ json_encode($e->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-secondary">No events.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
        @if ($payment->refunds->isNotEmpty())
            <div class="card">
                <div class="card-header"><h3 class="card-title">Refunds</h3></div>
                <table class="table card-table table-sm">
                    @foreach ($payment->refunds as $r)
                        <tr><td>@can('refunds')<a href="{{ route('dashboard.refunds.show', $r) }}">{{ $r->reference }}</a>@else{{ $r->reference }}@endcan</td><td>{{ $r->method }}</td><td class="text-end"><x-admin.money :amount="$r->amount" /></td><td><x-admin.status :status="$r->status" /></td></tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@push('modals')
    @can('refunds')
        <x-admin.modal id="refundNew" title="Request a refund" :action="route('dashboard.refunds.store', $payment)" submit="Request refund" submitClass="btn-danger">
            <x-admin.input name="amount" type="number" step="0.01" min="0.01" :max="number_format($payment->refundableMinor() / 100, 2, '.', '')" label="Amount" required :hint="'Up to '.number_format($payment->refundableMinor() / 100, 2)" />
            <x-admin.input name="reason" label="Reason" required maxlength="250" />
        </x-admin.modal>
    @endcan
@endpush
