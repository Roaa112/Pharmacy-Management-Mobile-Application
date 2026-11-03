@extends('admin.layout')

@section('title', 'Refunds')
@section('pretitle', 'Sales')
@section('subtitle', 'Refunds come from accepted returns, canceled paid orders, or a manual request on a payment. Online payments are refunded through the provider; cash refunds are recorded with a reference.')

@section('actions')
    <x-admin.data-transfer :entities="['refunds']" />
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-4"><x-admin.stat label="Open refunds" icon="receipt-refund" tone="orange" :value="(int) $open->count" :hint="number_format((float) $open->amount, 2).' to pay back'" :href="route('dashboard.refunds.index', ['status' => 'pending'])" /></div>
</div>
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select w-auto" aria-label="Status"><option value="">All statuses</option>@foreach (['pending', 'processing', 'succeeded', 'failed', 'canceled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
            <select name="method" class="form-select w-auto" aria-label="Method"><option value="">All methods</option>@foreach (['gateway', 'cash', 'manual'] as $m)<option @selected(request('method') === $m)>{{ $m }}</option>@endforeach</select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($refunds->isEmpty())
        <x-admin.empty icon="receipt-refund" title="No refunds" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reference</th><th>Order</th><th>Customer</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th>Reason</th><th>Requested</th></tr></thead>
                @foreach ($refunds as $r)
                    <tr>
                        <td><a href="{{ route('dashboard.refunds.show', $r) }}" class="fw-medium">{{ $r->reference }}</a></td>
                        <td><a href="{{ route('dashboard.orders.show', $r->order_id) }}">#{{ $r->order_id }}</a></td>
                        <td>{{ $r->order?->user?->name ?? '—' }}</td>
                        <td>{{ $r->method }}</td>
                        <td class="text-end"><x-admin.money :amount="$r->amount" /></td>
                        <td><x-admin.status :status="$r->status" /></td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($r->reason, 50) }}</td>
                        <td class="small text-secondary">{{ $r->created_at->format('Y-m-d') }} {{ $r->requester?->name }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$refunds" />
    @endif
</div>
@endsection
