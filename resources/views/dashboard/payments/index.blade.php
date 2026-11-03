@extends('admin.layout')

@section('title', 'Payments')
@section('pretitle', 'Sales')
@section('subtitle', 'Every payment attempt. Online payments become paid only on verified provider evidence; there is no manual "mark as paid".')

@section('actions')
    <x-admin.data-transfer :entities="['payments']" />
    <a href="{{ route('dashboard.cod.index') }}" class="btn btn-outline-primary"><i class="ti ti-cash me-1"></i>COD reconciliation</a>
    @can('refunds')<a href="{{ route('dashboard.refunds.index') }}" class="btn btn-outline-primary"><i class="ti ti-receipt-refund me-1"></i>Refunds{{ $pendingRefunds ? ' ('.$pendingRefunds.')' : '' }}</a>@endcan
    <a href="{{ route('dashboard.payments.events') }}" class="btn btn-outline-primary"><i class="ti ti-webhook me-1"></i>Provider events</a>
    <a href="{{ route('dashboard.payments.reports') }}" class="btn btn-outline-primary"><i class="ti ti-chart-bar me-1"></i>Reports</a>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
            <div class="text-secondary small fw-medium text-uppercase mb-1">Paymob</div>
            @if ($paymob['missing'])
                <div class="text-orange"><i class="ti ti-plug-off me-1"></i>Not configured ({{ $paymob['environment'] }}, {{ $paymob['flow'] }} flow): online payment is unavailable to customers.</div>
                <div class="small text-secondary mt-1">Missing environment settings: {{ implode(', ', $paymob['missing']) }}. Run <code>php artisan payments:paymob-check</code> after setting them.</div>
            @else
                <div class="text-green"><i class="ti ti-plug-connected me-1"></i>Configured — {{ $paymob['environment'] }} environment, {{ $paymob['flow'] }} flow.</div>
            @endif
        </div></div>
    </div>
    <div class="col-md-3"><x-admin.stat label="Rejected callbacks (7 days)" icon="shield-x" :tone="$rejectedEvents ? 'red' : 'secondary'" :value="$rejectedEvents" hint="Invalid signature or mismatched amount" :href="route('dashboard.payments.events', ['result' => 'rejected'])" /></div>
    <div class="col-md-3"><x-admin.stat label="Needs review" icon="alert-triangle" tone="orange" :value="\App\Models\Payment::where('metadata', 'like', '%needs_review%')->count()" :href="route('dashboard.payments.index', ['needs_review' => 1])" /></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Totals by method and status</h3></div>
    <div class="table-responsive">
        <table class="table card-table table-sm">
            <thead><tr><th>Method</th><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount</th><th class="text-end">Refunded</th></tr></thead>
            @foreach ($totals as $t)
                <tr><td>{{ strtoupper($t->provider) }}</td><td><x-admin.status :status="$t->status instanceof \App\Enums\PaymentStatus ? $t->status->value : $t->status" /></td><td class="text-end">{{ $t->count }}</td><td class="text-end"><x-admin.money :amount="$t->amount" /></td><td class="text-end"><x-admin.money :amount="$t->refunded" /></td></tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-3"><input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Reference, transaction or #order" aria-label="Search"></div>
            <div class="col-md-2"><select name="provider" class="form-select" aria-label="Method"><option value="">All methods</option>@foreach (['cod' => 'Cash on delivery', 'paymob' => 'Paymob', 'manual_transfer' => 'Legacy transfer'] as $v => $l)<option value="{{ $v }}" @selected(request('provider') === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select" aria-label="Status"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" aria-label="From"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control" aria-label="To"></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
        </form>
    </div>
    @if ($payments->isEmpty())
        <x-admin.empty icon="credit-card" title="No payments" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reference</th><th>Order</th><th>Customer</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th>Created</th></tr></thead>
                @foreach ($payments as $p)
                    <tr>
                        <td><a href="{{ route('dashboard.payments.show', $p) }}" class="fw-medium">{{ $p->internal_reference }}</a>@if (!empty($p->metadata['needs_review']))<i class="ti ti-alert-triangle text-orange ms-1" title="{{ $p->metadata['needs_review'] }}"></i>@endif</td>
                        <td><a href="{{ route('dashboard.orders.show', $p->order_id) }}">#{{ $p->order_id }}</a></td>
                        <td>{{ $p->order?->user?->name ?? '—' }}</td>
                        <td>{{ $p->method?->name_en ?? strtoupper($p->provider) }}@if ($p->method?->is_legacy)<span class="badge bg-secondary-lt ms-1">legacy</span>@endif</td>
                        <td class="text-end"><x-admin.money :amount="$p->amount" /></td>
                        <td><x-admin.status :status="$p->status->value" />@if ($p->isCod() && $p->settled_at)<span class="badge bg-teal-lt ms-1">settled</span>@endif</td>
                        <td class="small text-secondary">{{ $p->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$payments" />
    @endif
</div>
@endsection
