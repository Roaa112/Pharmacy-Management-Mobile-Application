@extends('admin.layout')

@section('title', ($size->product->name_en ?? 'Product').' — '.$size->size)
@section('pretitle', 'Inventory · SKU '.($size->sku ?? '#'.$size->id))
@section('subtitle', 'How the current balance came to be: lifecycle totals, balances per warehouse and every movement.')

@section('actions')
    <a href="{{ route('dashboard.inventory.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Inventory</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#stockOperation"><i class="ti ti-adjustments me-1"></i>Stock operation</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#skuDetails"><i class="ti ti-barcode me-1"></i>SKU details</button>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-6 col-lg-2"><x-admin.stat label="Available" icon="shopping-bag" :value="number_format($current['available'])" /></div>
    <div class="col-6 col-lg-2"><x-admin.stat label="On hand" icon="building-warehouse" tone="blue" :value="number_format($current['on_hand'])" /></div>
    <div class="col-6 col-lg-2"><x-admin.stat label="Reserved" icon="lock" tone="indigo" :value="number_format($current['reserved'])" /></div>
    <div class="col-6 col-lg-2"><x-admin.stat label="Damaged" icon="alert-octagon" tone="red" :value="number_format($current['damaged'])" /></div>
    <div class="col-6 col-lg-2"><x-admin.stat label="Quarantine" icon="shield-half" tone="orange" :value="number_format($current['quarantined'])" /></div>
    <div class="col-6 col-lg-2"><x-admin.stat label="In transit" icon="truck" tone="cyan" :value="number_format($current['in_transit'])" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Lifecycle totals</h3></div>
            <table class="table card-table table-sm">
                @foreach ($lifecycle as $label => $value)
                    <tr class="{{ $value ? '' : 'text-secondary' }}"><td>{{ $label }}</td><td class="text-end fw-medium">{{ number_format($value) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>On hand = sum of on-hand ledger rows</td><td class="text-end">{{ number_format($ledgerOnHand) }} @if ($ledgerOnHand !== $current['on_hand'])<span class="text-red" title="Ledger and balance disagree">≠ {{ $current['on_hand'] }}</span>@else<i class="ti ti-check text-green"></i>@endif</td></tr>
                <tr><td>Stock value at cost</td><td class="text-end">{{ $current['value_at_cost'] !== null ? number_format($current['value_at_cost'], 2).' '.config('pharmacy.currency') : 'no cost recorded' }}</td></tr>
            </table>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Per warehouse</h3></div>
            <table class="table card-table table-sm">
                <thead><tr><th>Warehouse</th><th class="text-end">On hand</th><th class="text-end">Res.</th><th class="text-end">Dmg</th><th class="text-end">Quar.</th><th class="text-end">Transit</th></tr></thead>
                @forelse ($size->balances as $b)
                    <tr><td>{{ $b->warehouse->code }}</td><td class="text-end">{{ $b->on_hand }}</td><td class="text-end">{{ $b->reserved }}</td><td class="text-end">{{ $b->damaged }}</td><td class="text-end">{{ $b->quarantined }}</td><td class="text-end">{{ $b->in_transit }}</td></tr>
                @empty
                    <tr><td colspan="6" class="text-secondary">No stock recorded yet.</td></tr>
                @endforelse
            </table>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Batches</h3><div class="card-actions small text-secondary">first expiry first out</div></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <thead><tr><th>Lot · expiry</th><th>WH</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Other</th></tr></thead>
                    @forelse ($batches as $b)
                        <tr class="{{ $b->batch->isExpired() ? 'table-danger' : (!$b->batch->isSellable() ? 'table-warning' : '') }}">
                            <td>{{ $b->batch->label() }}@if ($b->batch->isExpired()) <span class="badge bg-red-lt">expired</span>@elseif (!$b->batch->isSellable()) <span class="badge bg-yellow-lt">not sold</span>@endif</td>
                            <td>{{ $b->warehouse->code }}</td>
                            <td class="text-end">{{ $b->on_hand }}</td>
                            <td class="text-end">{{ $b->reserved }}</td>
                            <td class="text-end small text-secondary">{{ $b->damaged }} dmg · {{ $b->quarantined }} qtn · {{ $b->in_transit }} transit</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-secondary">No stock recorded.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Held for orders</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($reservations as $r)
                    <a href="{{ route('dashboard.orders.show', $r->orderItem->order_id) }}" class="list-group-item d-flex justify-content-between"><span>Order #{{ $r->orderItem->order_id }}</span><span>{{ $r->quantity }} · {{ $r->warehouse->code }}@if ($r->batch) · {{ $r->batch->label() }}@endif</span></a>
                @empty
                    <div class="list-group-item text-secondary">No active reservations.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Timeline</h3></div>
            <div class="table-responsive">
                <table class="table card-table table-sm table-vcenter">
                    <thead><tr><th>When</th><th>Type</th><th>Wh.</th><th>Bucket</th><th class="text-end">Change</th><th class="text-end">After</th><th>Reason</th></tr></thead>
                    @foreach ($timeline as $m)
                        <tr>
                            <td class="small text-nowrap">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                            <td><span class="badge bg-azure-lt">{{ str_replace('_', ' ', $m->type) }}</span></td>
                            <td class="small">{{ $m->warehouse?->code ?? 'legacy' }}</td>
                            <td class="small text-secondary">{{ str_replace('_', ' ', $m->bucket) }}</td>
                            <td class="text-end fw-bold {{ $m->quantity_change < 0 ? 'text-red' : 'text-green' }}">{{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}</td>
                            <td class="text-end">{{ $m->quantity_after }}</td>
                            <td class="small">{{ $m->admin?->name ?? ($m->user?->name ?? 'System') }}<div class="text-secondary">{{ \Illuminate\Support\Str::limit($m->reason, 70) }}</div></td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <x-admin.pagination :paginator="$timeline" />
        </div>
    </div>
</div>
@endsection

@push('modals')
    <x-admin.modal id="stockOperation" title="Stock operation" :action="route('dashboard.inventory.operate', $size)" submit="Apply">
        <div class="mb-3"><label class="form-label required" for="op">Operation</label>
            <select id="op" name="operation" class="form-select" required>
                <option value="adjust">Adjust on-hand units (+/−)</option>
                <option value="damage">Move sellable units to damaged</option>
                <option value="write_off">Write off damaged units</option>
                <option value="inspect">Inspect quarantined (returned) units</option>
            </select></div>
        <div class="mb-3"><label class="form-label required" for="wh">Warehouse</label>
            <select id="wh" name="warehouse_id" class="form-select" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label" for="batch">Batch</label>
            <select id="batch" name="batch_id" class="form-select"><option value="">Automatic (first expiry first out; units added: unknown lot)</option>@foreach ($batchOptions as $option)<option value="{{ $option->id }}">{{ $option->label() }}</option>@endforeach</select></div>
        <x-admin.input name="quantity" type="number" label="Quantity" hint="Adjust: negative to remove. Damage / write-off: units." />
        <div class="row">
            <div class="col-4"><x-admin.input name="restock" type="number" min="0" label="Back on sale" hint="Inspect only" /></div>
            <div class="col-4"><x-admin.input name="damaged" type="number" min="0" label="To damaged" /></div>
            <div class="col-4"><x-admin.input name="disposed" type="number" min="0" label="Disposed" /></div>
        </div>
        <x-admin.input name="reason" label="Reason" required maxlength="255" hint="Recorded in the ledger and the audit log." />
        <x-admin.input name="notes" type="textarea" label="Notes" />
    </x-admin.modal>

    <x-admin.modal id="skuDetails" title="SKU details" :action="route('dashboard.inventory.updateSku', $size)" method="PUT">
        <div class="row">
            <x-admin.input col="col-md-6 mb-3" name="sku" label="SKU" :value="$size->sku" />
            <x-admin.input col="col-md-6 mb-3" name="barcode" label="Barcode" :value="$size->barcode" />
            <x-admin.input col="col-md-6 mb-3" name="color" label="Colour" :value="$size->color" />
            <x-admin.input col="col-md-6 mb-3" name="cost_price" type="number" step="0.01" min="0" label="Cost price" :value="$size->cost_price" hint="Weighted average, updated by purchase receipts." />
            <x-admin.input col="col-md-3 mb-3" name="min_stock" type="number" min="0" label="Min" :value="$size->min_stock" />
            <x-admin.input col="col-md-3 mb-3" name="max_stock" type="number" min="0" label="Max" :value="$size->max_stock" />
            <x-admin.input col="col-md-3 mb-3" name="reorder_point" type="number" min="0" label="Reorder at" :value="$size->reorder_point" />
            <x-admin.input col="col-md-3 mb-3" name="reorder_quantity" type="number" min="1" label="Reorder qty" :value="$size->reorder_quantity" />
        </div>
    </x-admin.modal>
@endpush
