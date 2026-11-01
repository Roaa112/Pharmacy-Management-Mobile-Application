@extends('admin.layout')

@section('title', 'Purchase order '.$order->reference)
@section('pretitle', 'Purchasing · '.$order->supplier->name.' → '.$order->warehouse->code)

@section('actions')
    <a href="{{ route('dashboard.purchase_orders.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Purchase orders</a>
    @if ($order->status === 'draft')
        <form method="POST" action="{{ route('dashboard.purchase_orders.order', $order) }}">@csrf<button class="btn btn-primary">Approve &amp; send</button></form>
    @endif
    @if (in_array($order->status, ['draft', 'ordered'], true))
        <form method="POST" action="{{ route('dashboard.purchase_orders.cancel', $order) }}">@csrf<button class="btn btn-outline-danger">Cancel</button></form>
    @endif
    @if (in_array($order->status, ['partially_received', 'received'], true))
        <form method="POST" action="{{ route('dashboard.purchase_orders.close', $order) }}">@csrf<button class="btn btn-outline-secondary">Close</button></form>
    @endif
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-8">
        <form method="POST" action="{{ route('dashboard.purchase_orders.receive', $order) }}" class="card">
            @csrf
            <div class="card-header"><h3 class="card-title">Lines</h3><div class="card-actions"><x-admin.status :status="$order->status" /></div></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Damaged</th><th class="text-end">Outstanding</th><th class="text-end">Unit cost</th>
                        @if ($order->canReceive())<th class="w-1">Receive good</th><th class="w-1">Damaged</th>@endif</tr></thead>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>{{ $item->size?->product?->name_en ?? '—' }} — {{ $item->size?->size }}<div class="small text-secondary">{{ $item->size?->sku }}</div></td>
                            <td class="text-end">{{ $item->quantity_ordered }}</td>
                            <td class="text-end">{{ $item->quantity_received }}</td>
                            <td class="text-end">{{ $item->quantity_damaged }}</td>
                            <td class="text-end fw-bold">{{ $item->outstanding }}</td>
                            <td class="text-end"><x-admin.money :amount="$item->unit_cost" /></td>
                            @if ($order->canReceive())
                                <td><input type="number" min="0" max="{{ $item->outstanding }}" name="lines[{{ $item->id }}][received]" class="form-control form-control-sm" style="width: 6rem" aria-label="Good units received"></td>
                                <td><input type="number" min="0" max="{{ $item->outstanding }}" name="lines[{{ $item->id }}][damaged]" class="form-control form-control-sm" style="width: 5rem" aria-label="Damaged units received"></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            </div>
            @if ($order->canReceive())
                <div class="card-footer d-flex flex-wrap gap-2">
                    <input name="supplier_invoice_number" class="form-control w-auto" placeholder="Supplier invoice / delivery note" aria-label="Supplier invoice number">
                    <input name="notes" class="form-control w-auto flex-fill" placeholder="Notes" aria-label="Notes">
                    <button class="btn btn-primary">Receive into {{ $order->warehouse->code }}</button>
                </div>
            @endif
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <table class="table card-table table-sm">
                <tr><td>Subtotal</td><td class="text-end"><x-admin.money :amount="$order->subtotal" /></td></tr>
                <tr><td>Tax</td><td class="text-end"><x-admin.money :amount="$order->tax_amount" /></td></tr>
                <tr><td>Shipping</td><td class="text-end"><x-admin.money :amount="$order->shipping_cost" /></td></tr>
                <tr class="fw-bold"><td>Total</td><td class="text-end"><x-admin.money :amount="$order->total" /></td></tr>
                <tr><td>Expected</td><td class="text-end">{{ $order->expected_at?->format('Y-m-d') ?? '—' }}</td></tr>
                <tr><td>Created by</td><td class="text-end">{{ $order->creator?->name ?? '—' }}</td></tr>
            </table>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Receipts</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($order->receipts as $r)
                    <div class="list-group-item small"><div class="fw-medium">{{ $r->reference }} · {{ $r->received_at->format('Y-m-d H:i') }}</div>
                        {{ $r->items->sum('quantity_received') }} good, {{ $r->items->sum('quantity_damaged') }} damaged · {{ $r->receiver?->name ?? '—' }}{{ $r->supplier_invoice_number ? ' · inv. '.$r->supplier_invoice_number : '' }}</div>
                @empty <div class="list-group-item text-secondary small">Nothing received yet.</div> @endforelse
            </div>
        </div>
        @if ($order->invoices->isNotEmpty())
            <div class="card"><div class="card-header"><h3 class="card-title">Supplier invoices</h3></div>
                <table class="table card-table table-sm">@foreach ($order->invoices as $inv)<tr><td>{{ $inv->number }}</td><td class="text-end"><x-admin.money :amount="$inv->amount" /></td></tr>@endforeach</table></div>
        @endif
    </div>
</div>
@endsection
