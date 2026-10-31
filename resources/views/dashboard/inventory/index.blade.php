@extends('admin.layout')

@php $tabs = ['all' => 'All sizes', 'low' => 'Low stock', 'out' => 'Out of stock', 'ok' => 'Healthy']; @endphp

@section('title', 'Stock overview')
@section('pretitle', 'Inventory')
@section('subtitle', 'Stock per product size. Change stock from the product form; every change is recorded in stock movements.')

@section('actions')
    <a href="{{ route('dashboard.inventory.movements') }}" class="btn btn-outline-primary"><i class="ti ti-arrows-exchange me-1"></i>Stock movements</a>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Units in stock" icon="packages" :value="number_format($summary['units'])" :hint="$summary['sizes'].' sizes'.($summary['quarantined'] ? ' · '.number_format($summary['quarantined']).' returned units in quarantine' : '')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Stock value" icon="coins" tone="teal" :value="number_format($summary['value'], 2).' '.config('pharmacy.currency')" hint="At selling price (no cost price recorded)" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Low stock" icon="alert-triangle" tone="orange" :value="number_format($summary['low'])" :hint="'1 – '.$threshold.' units'" :href="route('dashboard.inventory.index', ['stock' => 'low'])" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Out of stock" icon="package-off" tone="red" :value="number_format($summary['out'])" hint="Shown as out of stock in the app" :href="route('dashboard.inventory.index', ['stock' => 'out'])" /></div>
</div>

<div class="card">
    <div class="card-header flex-column align-items-stretch gap-3">
        <ul class="nav nav-tabs card-header-tabs">
            @foreach ($tabs as $value => $label)
                <li class="nav-item">
                    <a href="{{ route('dashboard.inventory.index', array_filter(['stock' => $value === 'all' ? null : $value, 'search' => $search])) }}" class="nav-link {{ $filter === $value ? 'active' : '' }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="d-flex gap-2" style="max-width: 28rem">
            @if ($filter !== 'all') <input type="hidden" name="stock" value="{{ $filter }}"> @endif
            <div class="input-icon flex-fill">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Product name">
            </div>
            <button class="btn btn-primary">Search</button>
        </form>
    </div>

    @if ($sizes->isEmpty())
        <x-admin.empty icon="packages" title="Nothing to show" :text="$filter === 'all' ? null : 'No sizes match this filter.'" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Product</th><th>Size</th><th>Category</th><th class="text-end">Price</th><th class="text-end">Stock</th><th class="text-end d-none d-md-table-cell" title="Returned units, not for sale">Quarantine</th><th>State</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($sizes as $size)
                    @php $state = $size->stock <= 0 ? 'out' : ($size->stock <= $threshold ? 'low' : 'ok'); @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <x-admin.thumb :src="$size->product?->image ? asset($size->product->image) : null" />
                                <span class="fw-medium">{{ $size->product->name_en ?? '—' }}</span>
                            </div>
                        </td>
                        <td>{{ $size->size }}</td>
                        <td class="small text-secondary">{{ $size->product?->category?->name_en ?? '—' }}</td>
                        <td class="text-end"><x-admin.money :amount="$size->price" /></td>
                        <td class="text-end fw-bold">{{ number_format($size->stock) }}</td>
                        <td class="text-end d-none d-md-table-cell {{ $size->quarantined_stock ? 'text-orange' : 'text-secondary' }}">{{ number_format($size->quarantined_stock) }}</td>
                        <td><x-admin.status :status="$state" /></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <a href="{{ route('dashboard.inventory.movements', ['size' => $size->id]) }}" class="btn btn-sm btn-ghost-secondary btn-icon" title="History" aria-label="Stock history"><i class="ti ti-history"></i></a>
                                <a href="{{ route('dashboard.products.edit', $size->product_id) }}" class="btn btn-sm btn-ghost-primary btn-icon" title="Edit stock" aria-label="Edit stock"><i class="ti ti-edit"></i></a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$sizes" />
    @endif
</div>
@endsection
