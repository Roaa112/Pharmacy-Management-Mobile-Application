@extends('admin.layout')

@section('title', 'Products')
@section('pretitle', 'Catalog')

@section('actions')
    @can('products.import')<a href="{{ route('dashboard.data.index') }}" class="btn btn-outline-secondary"><i class="ti ti-file-import me-1"></i>Import</a>@endcan
    @can('products.export')<a href="{{ route('dashboard.data.index', ['export' => 'products']) }}" class="btn btn-outline-secondary"><i class="ti ti-file-export me-1"></i>Export</a>@endcan
    @can('products.create')<a href="{{ route('dashboard.products.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New product</a>@endcan
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100 align-items-center">
            <div class="col-md-5">
                <div class="input-icon">
                    <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                    <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Search by Arabic or English name">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->parent_id ? '— ' : '' }}{{ $category->name_en }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="stock" class="form-select" aria-label="Stock">
                    <option value="">Any stock</option>
                    <option value="low" @selected(request('stock') === 'low')>Low stock</option>
                    <option value="out" @selected(request('stock') === 'out')>Out of stock</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit">Filter</button>
                @if (request()->hasAny(['search', 'category', 'stock']))
                    <a href="{{ route('dashboard.products.index') }}" class="btn btn-icon btn-outline-secondary" title="Clear filters"><i class="ti ti-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    @if ($products->isEmpty())
        <x-admin.empty icon="pill-off" title="No products found" :text="request()->hasAny(['search', 'category', 'stock']) ? 'Try different filters.' : 'Add your first product to start selling.'">
            <a href="{{ route('dashboard.products.create') }}" class="btn btn-primary btn-sm">New product</a>
        </x-admin.empty>
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                <tr>
                    <th>Product</th>
                    <th>Category / brand</th>
                    <th>Sizes &amp; price</th>
                    <th>Stock</th>
                    <th>Promotion</th>
                    <th class="w-1"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($products as $product)
                    @php
                        $units = $product->sizes->sum('stock');
                        $stockState = $units <= 0 ? 'out' : ($product->sizes->contains(fn ($s) => $s->stock > 0 && $s->stock <= $threshold) ? 'low' : 'ok');
                    @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <x-admin.thumb :src="$product->image ? asset($product->image) : null" :alt="$product->name_en" />
                                <div class="min-w-0">
                                    <a href="{{ route('dashboard.products.edit', $product) }}" class="fw-medium text-reset d-block text-truncate" style="max-width: 18rem">{{ $product->name_en }}</a>
                                    <div class="small text-secondary text-truncate" dir="rtl" style="max-width: 18rem">{{ $product->name_ar }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div>{{ $product->category->name_en ?? '—' }}</div>
                            <div class="small text-secondary">{{ $product->brand->name_en ?? 'No brand' }}</div>
                        </td>
                        <td>
                            @forelse ($product->sizes as $size)
                                <div class="small"><span class="text-secondary">{{ $size->size }}</span> · <x-admin.money :amount="$size->price" /></div>
                            @empty
                                <span class="text-secondary small">No sizes</span>
                            @endforelse
                        </td>
                        <td>
                            <x-admin.status :status="$stockState" />
                            <div class="small text-secondary mt-1">{{ number_format($units) }} units</div>
                        </td>
                        <td>
                            @if ($product->saleable_type === \App\Models\FlashSale::class && $product->saleable)
                                <span class="badge bg-yellow-lt"><i class="ti ti-bolt me-1"></i>{{ rtrim(rtrim($product->saleable->discount_value, '0'), '.') }}% flash</span>
                                <div class="small text-secondary mt-1">{{ $product->saleable->is_active ? 'Active' : 'Inactive' }} · {{ $product->saleable->date }}</div>
                            @else
                                <span class="text-secondary small">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <a href="{{ route('dashboard.products.edit', $product) }}" class="btn btn-sm btn-ghost-primary btn-icon" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></a>
                                <x-admin.delete :action="route('dashboard.products.destroy', $product)"
                                                confirm="Delete this product? It disappears from the app but stays in past orders." />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$products" />
    @endif
</div>
@endsection
