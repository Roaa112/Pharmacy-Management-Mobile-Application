@extends('admin.layout')

@section('title', $supplier->name)
@section('pretitle', 'Purchasing · supplier '.$supplier->code)

@section('actions')
    <a href="{{ route('dashboard.suppliers.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Suppliers</a>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#supplierEdit">Edit</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#invoiceNew">Record invoice</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentNew">Record payment</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#returnNew">Return goods</button>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-3"><x-admin.stat label="Balance owed" icon="scale" :tone="(float) $balance > 0 ? 'orange' : 'green'" :value="number_format((float) $balance, 2)" hint="Invoices − payments − return credits" /></div>
    <div class="col-md-3"><x-admin.stat label="Invoiced" icon="file-invoice" :value="number_format((float) $supplier->invoices->sum('amount'), 2)" /></div>
    <div class="col-md-3"><x-admin.stat label="Paid" icon="cash" tone="teal" :value="number_format((float) $supplier->payments->sum('amount'), 2)" /></div>
    <div class="col-md-3"><x-admin.stat label="Contact" icon="phone" tone="blue" :value="$supplier->phone ?? '—'" :hint="$supplier->contact_name" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Purchase orders</h3></div>
            <table class="table card-table table-sm">
                @forelse ($supplier->purchaseOrders as $po)
                    <tr><td><a href="{{ route('dashboard.purchase_orders.show', $po) }}">{{ $po->reference }}</a></td><td><x-admin.status :status="$po->status" /></td><td class="text-end"><x-admin.money :amount="$po->total" /></td><td class="small text-secondary">{{ $po->expected_at?->format('Y-m-d') }}</td></tr>
                @empty <tr><td class="text-secondary">None.</td></tr> @endforelse
            </table>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Returns to supplier</h3></div>
            <table class="table card-table table-sm">
                @forelse ($supplier->returns as $r)
                    <tr>
                        <td>{{ $r->reference }}<div class="small text-secondary">{{ $r->items->map(fn ($i) => ($i->size?->product?->name_en ?? '—').' ×'.$i->quantity)->implode(', ') }}</div></td>
                        <td><x-admin.status :status="$r->status" /></td>
                        <td class="text-end"><x-admin.money :amount="$r->credit_amount" /></td>
                        <td class="text-nowrap">@if ($r->status === 'draft')
                            <form method="POST" action="{{ route('dashboard.supplier_returns.dispatch', $r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Dispatch</button></form>
                            <form method="POST" action="{{ route('dashboard.supplier_returns.cancel', $r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-ghost-danger">Cancel</button></form>
                        @endif</td>
                    </tr>
                @empty <tr><td class="text-secondary">None.</td></tr> @endforelse
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Invoices</h3></div>
            <table class="table card-table table-sm">
                @forelse ($supplier->invoices as $inv)
                    <tr><td>{{ $inv->number }}<div class="small text-secondary">{{ $inv->invoice_date->format('Y-m-d') }}{{ $inv->due_date ? ' · due '.$inv->due_date->format('Y-m-d') : '' }}</div></td>
                        <td class="text-end"><x-admin.money :amount="$inv->amount" /><div class="small text-secondary">paid {{ number_format((float) $inv->payments_sum_amount, 2) }}</div></td></tr>
                @empty <tr><td class="text-secondary">None.</td></tr> @endforelse
            </table>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Payments</h3></div>
            <table class="table card-table table-sm">
                @forelse ($supplier->payments as $p)
                    <tr><td>{{ $p->paid_on->format('Y-m-d') }}</td><td class="small">{{ $p->method }} {{ $p->reference }}</td><td class="text-end"><x-admin.money :amount="$p->amount" /></td></tr>
                @empty <tr><td class="text-secondary">None.</td></tr> @endforelse
            </table>
        </div>
    </div>
</div>
@endsection

@push('modals')
    <x-admin.modal id="supplierEdit" title="Edit supplier" :action="route('dashboard.suppliers.update', $supplier)" method="PUT" size="lg">
        @include('dashboard.suppliers.form', ['supplier' => $supplier])
    </x-admin.modal>
    <x-admin.modal id="invoiceNew" title="Record supplier invoice" :action="route('dashboard.suppliers.invoices.store', $supplier)">
        <x-admin.input name="number" label="Invoice number" required />
        <div class="mb-3"><label class="form-label">Purchase order</label><select name="purchase_order_id" class="form-select"><option value="">—</option>@foreach ($supplier->purchaseOrders as $po)<option value="{{ $po->id }}">{{ $po->reference }}</option>@endforeach</select></div>
        <div class="row"><x-admin.input col="col-6 mb-3" name="invoice_date" type="date" label="Date" required /><x-admin.input col="col-6 mb-3" name="due_date" type="date" label="Due" /></div>
        <x-admin.input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
        <x-admin.input name="notes" type="textarea" label="Notes" />
    </x-admin.modal>
    <x-admin.modal id="paymentNew" title="Record payment to supplier" :action="route('dashboard.suppliers.payments.store', $supplier)">
        {{-- One key per rendered form: a double submit records the payment once. --}}
        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <div class="mb-3"><label class="form-label">Invoice</label><select name="supplier_invoice_id" class="form-select"><option value="">On account</option>@foreach ($supplier->invoices as $inv)<option value="{{ $inv->id }}">{{ $inv->number }} ({{ number_format((float) $inv->amount - (float) $inv->payments_sum_amount, 2) }} left)</option>@endforeach</select></div>
        <div class="row"><x-admin.input col="col-6 mb-3" name="amount" type="number" step="0.01" min="0.01" label="Amount" required /><x-admin.input col="col-6 mb-3" name="paid_on" type="date" label="Paid on" required /></div>
        <div class="row"><x-admin.input col="col-6 mb-3" name="method" label="Method" hint="Bank transfer, cash…" /><x-admin.input col="col-6 mb-3" name="reference" label="Reference" /></div>
    </x-admin.modal>
    <x-admin.modal id="returnNew" title="Return goods to supplier" :action="route('dashboard.suppliers.returns.store', $supplier)" size="lg" submit="Draft return">
        <div class="mb-3"><label class="form-label required">From warehouse</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
        @include('dashboard.partials.line-items', ['id' => 'sreturn', 'sizes' => $sizes, 'cost' => true, 'bucket' => true])
        <x-admin.input name="reason" label="Reason" />
    </x-admin.modal>
@endpush
