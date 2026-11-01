@extends('admin.layout')

@section('title', 'Cash on delivery')
@section('pretitle', 'Payments')
@section('subtitle', 'Collection (the customer paid the courier or the counter) and settlement (the cash reached the pharmacy) are separate. Select collected payments and record what was actually handed over.')

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-3"><x-admin.stat label="Due from customers" icon="clock-dollar" :value="number_format((float) $due->amount, 2)" :hint="$due->count.' orders not collected yet'" /></div>
    <div class="col-md-3"><x-admin.stat label="Collected, not settled" icon="cash" tone="orange" :value="number_format((float) $unsettled->sum('collected_amount'), 2)" :hint="$unsettled->count().' payments'" /></div>
    <div class="col-md-3"><x-admin.stat label="Failed collections" icon="alert-triangle" tone="red" :value="$failedCollections" hint="Delivery failed; cash still due" /></div>
    <div class="col-md-3"><x-admin.stat label="Settlement differences" icon="scale" :tone="$discrepancy == 0 ? 'green' : 'red'" :value="number_format($discrepancy, 2)" hint="Received − expected, all time" /></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Held by courier / counter</h3></div>
    <table class="table card-table table-sm">
        @foreach ($byProvider as $row)
            <tr><td><a href="{{ route('dashboard.cod.index', ['provider' => $row->provider_id]) }}">{{ $providers->get($row->provider_id)?->name ?? 'Counter / no courier' }}</a></td><td class="text-end">{{ $row->count }} payments</td><td class="text-end fw-bold"><x-admin.money :amount="$row->amount" /></td></tr>
        @endforeach
    </table>
</div>

<form method="POST" action="{{ route('dashboard.cod.settle') }}" class="card mb-3">
    @csrf
    <div class="card-header"><h3 class="card-title">Collected, awaiting settlement</h3>
        <div class="card-actions">
            <select class="form-select form-select-sm" onchange="location.href='{{ route('dashboard.cod.index') }}' + (this.value ? '?provider=' + this.value : '')" aria-label="Courier">
                <option value="">All couriers</option>@foreach ($providers as $p)<option value="{{ $p->id }}" @selected($providerId === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
    </div>
    @if ($unsettled->isEmpty())
        <x-admin.empty icon="cash-off" title="Nothing awaiting settlement" />
    @else
        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead><tr><th class="w-1"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.cod-pick').forEach(c => { c.checked = this.checked; c.dispatchEvent(new Event('change')); })" aria-label="Select all"></th><th>Payment</th><th>Order</th><th>Customer</th><th>Courier</th><th>Collected</th><th class="text-end">Amount</th></tr></thead>
                @foreach ($unsettled as $p)
                    <tr>
                        <td><input type="checkbox" class="form-check-input cod-pick" name="payment_ids[]" value="{{ $p->id }}" data-amount="{{ $p->collected_amount }}" aria-label="Select"></td>
                        <td><a href="{{ route('dashboard.payments.show', $p) }}">{{ $p->internal_reference }}</a></td>
                        <td><a href="{{ route('dashboard.orders.show', $p->order_id) }}">#{{ $p->order_id }}</a></td>
                        <td>{{ $p->order?->user?->name }}</td>
                        <td>{{ $p->order?->latestShipment?->provider?->name ?? ($p->collected_via === 'counter' ? 'Counter' : '—') }}</td>
                        <td class="small text-secondary">{{ $p->collected_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-end"><x-admin.money :amount="$p->collected_amount" /></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div class="card-footer row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label">Selected</label><div class="fw-bold" id="cod-expected">0.00</div></div>
            <div class="col-md-2"><label class="form-label required" for="received">Cash received</label><input id="received" type="number" step="0.01" min="0" name="received_amount" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label" for="cprov">Courier</label><select id="cprov" name="provider_id" class="form-select"><option value="">Counter / none</option>@foreach ($providers as $p)<option value="{{ $p->id }}" @selected($providerId === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label" for="cref">Reference</label><input id="cref" name="received_reference" class="form-control" placeholder="Receipt / transfer"></div>
            <div class="col-md-2"><label class="form-label" for="cnotes">Notes</label><input id="cnotes" name="notes" class="form-control"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Record settlement</button></div>
        </div>
    @endif
</form>

<div class="card">
    <div class="card-header"><h3 class="card-title">Settlements</h3></div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead><tr><th>Reference</th><th>Courier</th><th class="text-end">Payments</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Difference</th><th>Settled</th></tr></thead>
            @forelse ($settlements as $s)
                <tr><td><a href="{{ route('dashboard.cod.settlements.show', $s) }}">{{ $s->reference }}</a></td><td>{{ $s->provider?->name ?? 'Counter' }}</td><td class="text-end">{{ $s->payments_count }}</td>
                    <td class="text-end"><x-admin.money :amount="$s->expected_amount" /></td><td class="text-end"><x-admin.money :amount="$s->received_amount" /></td>
                    <td class="text-end {{ (float) $s->difference < 0 ? 'text-red' : '' }}"><x-admin.money :amount="$s->difference" /></td><td class="small text-secondary">{{ $s->settled_at->format('Y-m-d H:i') }} {{ $s->settler?->name }}</td></tr>
            @empty
                <tr><td colspan="7" class="text-secondary">No settlements yet.</td></tr>
            @endforelse
        </table>
    </div>
    <x-admin.pagination :paginator="$settlements" />
</div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.cod-pick').forEach(function (box) {
            box.addEventListener('change', function () {
                var total = 0;
                document.querySelectorAll('.cod-pick:checked').forEach(function (c) { total += parseFloat(c.dataset.amount); });
                document.getElementById('cod-expected').textContent = total.toFixed(2);
                document.getElementById('received').value = total.toFixed(2);
            });
        });
    </script>
@endpush
