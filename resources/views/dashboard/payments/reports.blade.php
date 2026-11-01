@extends('admin.layout')

@section('title', 'Payment reports')
@section('pretitle', 'Payments')

@section('actions')
    <form method="GET" class="d-flex gap-1">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" aria-label="From">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" aria-label="To">
        <button class="btn btn-primary">Apply</button>
    </form>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-4"><x-admin.stat label="COD still due from customers" icon="clock-dollar" tone="orange" :value="number_format($cod['due'], 2)" /></div>
    <div class="col-md-4"><x-admin.stat label="COD collected, not settled" icon="cash" tone="red" :value="number_format($cod['collected_unsettled'], 2)" hint="Held by couriers or the counter" :href="route('dashboard.cod.index')" /></div>
    <div class="col-md-4"><x-admin.stat label="COD settled in period" icon="building-bank" tone="green" :value="number_format($cod['settled'], 2)" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">By method ({{ $from->format('M j') }} – {{ $to->format('M j') }})</h3></div>
            <table class="table card-table">
                <thead><tr><th>Method</th><th class="text-end">Attempts</th><th class="text-end">Paid</th><th class="text-end">Paid amount</th><th class="text-end">Failed</th><th class="text-end">Expired</th><th class="text-end">Success rate</th><th class="text-end">Refunded</th></tr></thead>
                @foreach ($byMethod as $row)
                    <tr><td>{{ strtoupper($row->provider) }}</td><td class="text-end">{{ $row->attempts }}</td><td class="text-end">{{ $row->paid_count }}</td><td class="text-end"><x-admin.money :amount="$row->paid_amount" /></td><td class="text-end">{{ $row->failed_count }}</td><td class="text-end">{{ $row->expired_count }}</td>
                        <td class="text-end">{{ $row->attempts ? round(100 * $row->paid_count / $row->attempts).'%' : '—' }}</td><td class="text-end"><x-admin.money :amount="$row->refunded_amount" /></td></tr>
                @endforeach
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Failure reasons</h3></div>
            <table class="table card-table table-sm">
                @forelse ($failureReasons as $r)<tr><td class="small">{{ $r->failure_reason }}</td><td class="text-end">{{ $r->count }}</td></tr>@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
            </table>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Refunds</h3></div>
            <table class="table card-table table-sm">
                @forelse ($refunds as $r)<tr><td>{{ $r->method }}</td><td><x-admin.status :status="$r->status" /></td><td class="text-end">{{ $r->count }}</td><td class="text-end"><x-admin.money :amount="$r->amount" /></td></tr>@empty<tr><td class="text-secondary">None.</td></tr>@endforelse
            </table>
        </div>
    </div>
</div>
@endsection
