@extends('admin.layout')

@php
    $tabs = ['all' => 'All SKUs', 'low' => 'Low stock', 'out' => 'Out of stock', 'overstock' => 'Overstock', 'reserved' => 'Reserved', 'damaged' => 'Damaged', 'quarantined' => 'In quarantine', 'ok' => 'Healthy'];
    $query = request()->except(['stock', 'page']);
@endphp

@section('title', 'Inventory')
@section('pretitle', 'Inventory')
@section('subtitle', 'Stock per SKU and warehouse. Available = on hand − reserved for orders. Every change is a movement in the stock ledger.')

@section('actions')
    <x-admin.data-transfer :entities="['inventory']" />
    <a href="{{ route('dashboard.inventory.movements') }}" class="btn btn-outline-primary"><i class="ti ti-arrows-exchange me-1"></i>Movements</a>
    <a href="{{ route('dashboard.inventory.reports') }}" class="btn btn-outline-primary"><i class="ti ti-report-analytics me-1"></i>Reports</a>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Available to sell" icon="packages" :value="number_format($summary['available'])" :hint="number_format($summary['skus']).' SKUs · '.number_format($summary['products']).' products'" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="On hand" icon="building-warehouse" tone="blue" :value="number_format($summary['on_hand'])" :hint="number_format($summary['reserved']).' reserved for orders'" :href="route('dashboard.inventory.index', ['stock' => 'reserved'])" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Stock value (cost)" icon="coins" tone="teal" :value="number_format($summary['value_cost'], 2).' '.config('pharmacy.currency')" :hint="'Retail '.number_format($summary['value_retail'], 2).($summary['without_cost'] ? ' · '.$summary['without_cost'].' SKUs without cost' : '')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Not sellable" icon="alert-octagon" tone="orange" :value="number_format($summary['damaged'] + $summary['quarantined'])" :hint="number_format($summary['damaged']).' damaged · '.number_format($summary['quarantined']).' quarantine · '.number_format($summary['in_transit']).' in transit'" :href="route('dashboard.inventory.index', ['stock' => 'quarantined'])" /></div>
    <div class="col-sm-4"><x-admin.stat label="Low stock" icon="alert-triangle" tone="orange" :value="number_format($summary['low'])" :hint="'At or below reorder point (default '.$threshold.')'" :href="route('dashboard.inventory.index', ['stock' => 'low'])" /></div>
    <div class="col-sm-4"><x-admin.stat label="Out of stock" icon="package-off" tone="red" :value="number_format($summary['out'])" hint="Shown as out of stock in the app" :href="route('dashboard.inventory.index', ['stock' => 'out'])" /></div>
    <div class="col-sm-4"><x-admin.stat label="Overstock" icon="stack-3" tone="purple" :value="number_format($summary['overstock'])" hint="Above the SKU's maximum" :href="route('dashboard.inventory.index', ['stock' => 'overstock'])" /></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Warehouses</h3>
        @can('inventory')<div class="card-actions"><a href="{{ route('dashboard.warehouses.index') }}" class="btn btn-sm btn-outline-primary">Manage</a></div>@endcan
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-sm">
            <thead><tr><th>Warehouse</th><th class="text-end">SKUs</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end">Damaged</th><th class="text-end">Quarantine</th><th class="text-end">In transit</th></tr></thead>
            <tbody>
            @foreach ($warehouseSummary as $w)
                <tr>
                    <td><a href="{{ route('dashboard.inventory.index', ['warehouse' => $w->id]) }}" class="fw-medium">{{ $w->code }}</a> {{ $w->name }}
                        @if ($w->is_default)<span class="badge bg-blue-lt ms-1">default</span>@endif
                        @unless ($w->is_sellable)<span class="badge bg-secondary-lt ms-1" title="Not counted in online stock">not sellable</span>@endunless
                        @unless ($w->is_active)<span class="badge bg-red-lt ms-1">inactive</span>@endunless
                    </td>
                    <td class="text-end">{{ number_format($w->skus) }}</td>
                    <td class="text-end">{{ number_format($w->on_hand) }}</td>
                    <td class="text-end">{{ number_format($w->reserved) }}</td>
                    <td class="text-end fw-bold">{{ number_format($w->on_hand - $w->reserved) }}</td>
                    <td class="text-end">{{ number_format($w->damaged) }}</td>
                    <td class="text-end">{{ number_format($w->quarantined) }}</td>
                    <td class="text-end">{{ number_format($w->in_transit) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header flex-column align-items-stretch gap-3">
        <ul class="nav nav-tabs card-header-tabs flex-nowrap overflow-auto">
            @foreach ($tabs as $value => $label)
                <li class="nav-item"><a href="{{ route('dashboard.inventory.index', array_filter($query + ['stock' => $value === 'all' ? null : $value])) }}" class="nav-link text-nowrap {{ $filter === $value ? 'active' : '' }}">{{ $label }}</a></li>
            @endforeach
        </ul>
        <form method="GET" class="row g-2">
            @if ($filter !== 'all') <input type="hidden" name="stock" value="{{ $filter }}"> @endif
            <div class="col-md-3"><input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name, SKU or barcode" aria-label="Search"></div>
            <div class="col-md-2"><select name="warehouse" class="form-select" aria-label="Warehouse"><option value="">All warehouses</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->code }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="category" class="form-select" aria-label="Category"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name_en }}</option>@endforeach</select></div>
            <div class="col-md-2"><input name="size_label" value="{{ request('size_label') }}" class="form-control" placeholder="Size" aria-label="Size"></div>
            <div class="col-md-2"><select name="color" class="form-select" aria-label="Colour"><option value="">Any colour</option>@foreach ($colors as $c)<option @selected(request('color') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
        </form>
    </div>

    @if ($sizes->isEmpty())
        <x-admin.empty icon="packages" title="Nothing to show" text="No SKUs match these filters." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Product</th><th>SKU</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end d-none d-lg-table-cell">Damaged</th><th class="text-end d-none d-lg-table-cell">Quarantine</th><th class="text-end d-none d-lg-table-cell">In transit</th><th>State</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($sizes as $size)
                    @php
                        $available = (int) $size->on_hand_total - (int) $size->reserved_total;
                        $state = $size->stock <= 0 ? 'out' : ($size->max_stock !== null && $size->stock > $size->max_stock ? 'overstock' : ($size->stock <= ($size->reorder_point ?? $threshold) ? 'low' : 'ok'));
                    @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <x-admin.thumb :src="$size->product?->image ? asset($size->product->image) : null" />
                                <div><div class="fw-medium">{{ $size->product->name_en ?? '—' }}</div>
                                    <div class="small text-secondary">{{ $size->size }}{{ $size->color ? ' · '.$size->color : '' }} · {{ $size->product?->category?->name_en ?? '—' }}</div></div>
                            </div>
                        </td>
                        <td class="small">{{ $size->sku ?? '—' }}@if ($size->barcode)<div class="text-secondary">{{ $size->barcode }}</div>@endif</td>
                        <td class="text-end">{{ number_format((int) $size->on_hand_total) }}</td>
                        <td class="text-end">{{ number_format((int) $size->reserved_total) }}</td>
                        <td class="text-end fw-bold">{{ number_format($available) }}</td>
                        <td class="text-end d-none d-lg-table-cell {{ $size->damaged_total ? 'text-red' : 'text-secondary' }}">{{ number_format((int) $size->damaged_total) }}</td>
                        <td class="text-end d-none d-lg-table-cell {{ $size->quarantined_total ? 'text-orange' : 'text-secondary' }}">{{ number_format((int) $size->quarantined_total) }}</td>
                        <td class="text-end d-none d-lg-table-cell text-secondary">{{ number_format((int) $size->in_transit_total) }}</td>
                        <td><x-admin.status :status="$state" /></td>
                        <td><a href="{{ route('dashboard.inventory.show', $size) }}" class="btn btn-sm btn-ghost-primary btn-icon" title="History and actions" aria-label="Open SKU"><i class="ti ti-history"></i></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$sizes" />
    @endif
</div>
@endsection
