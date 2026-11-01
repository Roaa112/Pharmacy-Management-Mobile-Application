@extends('admin.layout')

@section('title', 'Purchase orders')
@section('pretitle', 'Purchasing')
@section('subtitle', 'A purchase order never changes stock by itself; only receiving does, line by line, partially if needed.')

@section('actions')
    <a href="{{ route('dashboard.suppliers.index') }}" class="btn btn-outline-primary"><i class="ti ti-truck-loading me-1"></i>Suppliers</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#poNew"><i class="ti ti-plus me-1"></i>New purchase order</button>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex flex-wrap gap-2">
            <select name="status" class="form-select w-auto" aria-label="Status"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select>
            <select name="supplier" class="form-select w-auto" aria-label="Supplier"><option value="">All suppliers</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select>
            <label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="overdue" value="1" @checked(request()->boolean('overdue'))><span class="form-check-label">Overdue</span></label>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($orders->isEmpty())
        <x-admin.empty icon="file-invoice" title="No purchase orders" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reference</th><th>Supplier</th><th>Warehouse</th><th class="text-end">Lines</th><th class="text-end">Total</th><th>Expected</th><th>Status</th></tr></thead>
                @foreach ($orders as $po)
                    <tr>
                        <td><a href="{{ route('dashboard.purchase_orders.show', $po) }}" class="fw-medium">{{ $po->reference }}</a></td>
                        <td>{{ $po->supplier->name }}</td><td>{{ $po->warehouse->code }}</td>
                        <td class="text-end">{{ $po->items_count }}</td>
                        <td class="text-end"><x-admin.money :amount="$po->total" /></td>
                        <td class="small {{ $po->expected_at && $po->expected_at->isPast() && $po->canReceive() ? 'text-red' : 'text-secondary' }}">{{ $po->expected_at?->format('Y-m-d') ?? '—' }}</td>
                        <td><x-admin.status :status="$po->status" /></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$orders" />
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="poNew" title="New purchase order" :action="route('dashboard.purchase_orders.store')" size="xl" submit="Save draft">
        <div class="row">
            <div class="col-md-5 mb-3"><label class="form-label required">Supplier</label><select name="supplier_id" class="form-select" required>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <div class="col-md-4 mb-3"><label class="form-label required">Deliver to</label><select name="warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
            <x-admin.input col="col-md-3 mb-3" name="expected_at" type="date" label="Expected" />
        </div>
        @include('dashboard.partials.line-items', ['id' => 'po', 'sizes' => $sizes, 'cost' => true])
        <div class="row mt-3">
            <x-admin.input col="col-md-3 mb-3" name="tax_amount" type="number" step="0.01" min="0" label="Tax" />
            <x-admin.input col="col-md-3 mb-3" name="shipping_cost" type="number" step="0.01" min="0" label="Shipping" />
            <x-admin.input col="col-md-6 mb-3" name="notes" label="Notes" />
        </div>
    </x-admin.modal>
@endpush
