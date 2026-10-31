@extends('admin.layout')

@php
    $editing = $product->exists;
    // The current promotion comes from saleable_*: there is no promotion_type column.
    $hasFlashSale = $product->saleable_type === \App\Models\FlashSale::class;
    $promotion = old('promotion_type', $hasFlashSale ? 'flash_sale' : '');
    $flashSaleId = old('flash_sale_id', $hasFlashSale ? $product->saleable_id : null);
    $selectedIssues = old('health_issues', $editing ? $product->healthIssues->pluck('id')->all() : []);
    $sizes = old('sizes', $editing
        ? $product->sizes->map(fn ($s) => ['id' => $s->id, 'size' => $s->size, 'price' => $s->price, 'stock' => $s->stock, 'stock_original' => $s->stock])->all()
        : [['size' => '', 'price' => '', 'stock' => '']]);
@endphp

@section('title', $editing ? $product->name_en : 'New product')
@section('pretitle')
    <a href="{{ route('dashboard.products.index') }}" class="text-reset">Products</a> / {{ $editing ? 'Edit' : 'Create' }}
@endsection

@section('actions')
    <a href="{{ route('dashboard.products.index') }}" class="btn btn-outline-secondary">Back to products</a>
    <button type="submit" form="product-form" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ $editing ? 'Save changes' : 'Create product' }}</button>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/libs/tom-select-2.6.2/tom-select.bootstrap5.min.css') }}">
@endpush

@section('content')
<form id="product-form" method="POST" enctype="multipart/form-data"
      action="{{ $editing ? route('dashboard.products.update', $product) : route('dashboard.products.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Details</h3></div>
                <div class="card-body">
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$editing ? old('name_en', $product->name_en) : null" required maxlength="255" />
                        <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$editing ? old('name_ar', $product->name_ar) : null" required maxlength="255" dir="rtl" />
                        <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_en" label="Description (English)" :value="$editing ? old('description_en', $product->description_en) : null" rows="4" />
                        <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_ar" label="Description (Arabic)" :value="$editing ? old('description_ar', $product->description_ar) : null" rows="4" dir="rtl" />
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Sizes, prices &amp; stock</h3>
                        <div class="card-subtitle">Each size is sold separately. Stock changes are recorded in the stock ledger.</div>
                    </div>
                    <div class="card-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="add-size"><i class="ti ti-plus me-1"></i>Add size</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2 small text-secondary fw-medium mb-1 d-none d-md-flex">
                        <div class="col-md-5">Size</div><div class="col-md-3">Price ({{ config('pharmacy.currency') }})</div><div class="col-md-3">Stock</div>
                    </div>
                    <div id="sizes">
                        @foreach ($sizes as $i => $size)
                            <div class="row g-2 size-row align-items-center">
                                @if (!empty($size['id']))
                                    <input type="hidden" name="sizes[{{ $i }}][id]" value="{{ $size['id'] }}">
                                    <input type="hidden" name="sizes[{{ $i }}][stock_original]" value="{{ $size['stock_original'] ?? $size['stock'] }}">
                                @endif
                                <div class="col-md-5"><input type="text" name="sizes[{{ $i }}][size]" value="{{ $size['size'] }}" class="form-control" placeholder="e.g. 500 mg · 20 tablets" maxlength="50" aria-label="Size"></div>
                                <div class="col-md-3"><input type="number" name="sizes[{{ $i }}][price]" value="{{ $size['price'] }}" class="form-control" step="0.01" min="0" placeholder="0.00" aria-label="Price"></div>
                                <div class="col-md-3"><input type="number" name="sizes[{{ $i }}][stock]" value="{{ $size['stock'] }}" class="form-control" min="0" step="1" placeholder="0" aria-label="Stock"></div>
                                <div class="col-md-1 text-end"><button type="button" class="btn btn-ghost-danger btn-icon remove-size" title="Remove size" aria-label="Remove size"><i class="ti ti-trash"></i></button></div>
                            </div>
                        @endforeach
                    </div>
                    @if ($editing)
                        <div class="form-hint mt-3"><i class="ti ti-info-circle me-1"></i>If stock changed while you were editing (an order came in), your change is applied as a difference, so no sale is lost. Removing a size that was sold keeps it for order history at stock 0.</div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Images</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label {{ $editing ? '' : 'required' }}" for="image">Main image</label>
                            @if ($editing && $product->image)
                                <div class="mb-2"><x-admin.thumb :src="asset($product->image)" size="lg" alt="Current main image" /></div>
                            @endif
                            <input id="image" type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" @unless ($editing) required @endunless>
                            <small class="form-hint">JPG, PNG or WEBP, up to 2 MB.{{ $editing ? ' Leave empty to keep the current image.' : '' }}</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="images">Extra images</label>
                            @if ($editing && $product->productImages->isNotEmpty())
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    @foreach ($product->productImages as $img)
                                        <label class="text-center small">
                                            <x-admin.thumb :src="asset($img->image_path)" size="lg" alt="Extra image" />
                                            <span class="d-block mt-1"><input type="checkbox" class="form-check-input" name="remove_extra_images[]" value="{{ $img->id }}"> Remove</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            <input id="images" type="file" name="images[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Organisation</h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label required" for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-select" required>
                            <option value="">Choose a category</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id) == $cat->id)>{{ $cat->name_en }}</option>
                                @foreach ($cat->children as $sub)
                                    <option value="{{ $sub->id }}" @selected(old('category_id', $product->category_id) == $sub->id)>— {{ $sub->name_en }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="brand_id">Brand</label>
                        <select id="brand_id" name="brand_id" class="form-select">
                            <option value="">No brand</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @selected(old('brand_id', $product->brand_id) == $brand->id)>{{ $brand->name_en }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="health_issues">Health issues</label>
                        <select id="health_issues" name="health_issues[]" class="form-select" multiple placeholder="Search health issues…">
                            @foreach ($healthIssues as $issue)
                                <option value="{{ $issue->id }}" @selected(in_array($issue->id, $selectedIssues))>{{ $issue->name_en }}</option>
                            @endforeach
                        </select>
                        <small class="form-hint">Used to group products in the app.</small>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Promotion</h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="promotion_type">Promotion type</label>
                        <select id="promotion_type" name="promotion_type" class="form-select">
                            <option value="" @selected($promotion === '')>None</option>
                            <option value="flash_sale" @selected($promotion === 'flash_sale')>Attach to a flash sale</option>
                        </select>
                    </div>
                    <div id="flash-sale-field" @class(['d-none' => $promotion !== 'flash_sale'])>
                        <label class="form-label" for="flash_sale_id">Flash sale</label>
                        <select id="flash_sale_id" name="flash_sale_id" class="form-select">
                            @forelse ($flashSales as $sale)
                                <option value="{{ $sale->id }}" @selected($flashSaleId == $sale->id)>
                                    {{ rtrim(rtrim($sale->discount_value, '0'), '.') }}% · {{ $sale->date }} {{ \Illuminate\Support\Str::of($sale->time)->substr(0, 5) }} {{ $sale->is_active ? '(active)' : '(inactive)' }}
                                </option>
                            @empty
                                <option value="" disabled>No flash sales yet</option>
                            @endforelse
                        </select>
                    </div>
                </div>
            </div>

            @if ($editing)
                <div class="card">
                    <div class="card-body">
                        <div class="text-secondary small mb-2">Deleting hides the product from the app. It stays visible in past orders.</div>
                        <button type="submit" form="delete-product" class="btn btn-outline-danger w-100"><i class="ti ti-trash me-1"></i>Delete product</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>

@if ($editing)
    <form id="delete-product" action="{{ route('dashboard.products.destroy', $product) }}" method="POST" data-confirm="Delete this product? It disappears from the app but stays in past orders.">
        @csrf @method('DELETE')
    </form>
@endif

<template id="size-template">
    <div class="row g-2 size-row align-items-center">
        <div class="col-md-5"><input type="text" data-name="size" class="form-control" placeholder="e.g. 500 mg · 20 tablets" maxlength="50" aria-label="Size"></div>
        <div class="col-md-3"><input type="number" data-name="price" class="form-control" step="0.01" min="0" placeholder="0.00" aria-label="Price"></div>
        <div class="col-md-3"><input type="number" data-name="stock" class="form-control" min="0" step="1" placeholder="0" aria-label="Stock"></div>
        <div class="col-md-1 text-end"><button type="button" class="btn btn-ghost-danger btn-icon remove-size" title="Remove size" aria-label="Remove size"><i class="ti ti-trash"></i></button></div>
    </div>
</template>
@endsection

@push('scripts')
    <script src="{{ asset('assets/libs/tom-select-2.6.2/tom-select.complete.min.js') }}"></script>
    <script>
        (function () {
            new TomSelect('#health_issues', { plugins: ['remove_button'], maxOptions: null });

            // Sizes: rows get a fresh index so they never overwrite each other.
            const sizes = document.getElementById('sizes');
            let nextIndex = {{ count($sizes) }};
            document.getElementById('add-size').addEventListener('click', function () {
                const row = document.getElementById('size-template').content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[data-name]').forEach(input => input.name = `sizes[${nextIndex}][${input.dataset.name}]`);
                nextIndex++;
                sizes.appendChild(row);
                row.querySelector('input').focus();
            });
            sizes.addEventListener('click', function (event) {
                const button = event.target.closest('.remove-size');
                if (button) button.closest('.size-row').remove();
            });

            const promotion = document.getElementById('promotion_type');
            promotion.addEventListener('change', function () {
                document.getElementById('flash-sale-field').classList.toggle('d-none', this.value !== 'flash_sale');
            });
        })();
    </script>
@endpush
