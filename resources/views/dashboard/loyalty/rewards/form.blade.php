@extends('admin.layout')

@section('title', $reward->exists ? 'Edit reward' : 'New reward')
@section('pretitle', 'Loyalty · Rewards')

@section('actions')
    <a href="{{ route('dashboard.loyalty.rewards.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Rewards</a>
@endsection

@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $reward->exists ? route('dashboard.loyalty.rewards.update', $reward) : route('dashboard.loyalty.rewards.store') }}">
    @csrf
    @if ($reward->exists) @method('PUT') @endif
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <x-admin.input col="col-md-8 mb-3" name="title" label="Title" :value="old('title', $reward->title)" required maxlength="255" />
                        <x-admin.input col="col-md-4 mb-3" name="code" label="Code" :value="old('code', $reward->code)" required maxlength="40" hint="Letters, numbers, - and _. Used by imports." />
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required">Type</label>
                            <select name="type" class="form-select" id="reward-type">
                                @foreach ($types as $type)<option value="{{ $type }}" @selected(old('type', $reward->type) === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-8 mb-3" id="product-field">
                            <label class="form-label">Product size (for product rewards)</label>
                            <select name="product_size_id" class="form-select">
                                <option value="">—</option>
                                @foreach ($sizes as $size)
                                    <option value="{{ $size->id }}" @selected((int) old('product_size_id', $reward->product_size_id) === $size->id)>{{ $size->product?->name_en }} · {{ $size->size }}{{ $size->sku ? ' · '.$size->sku : '' }}</option>
                                @endforeach
                            </select>
                            <small class="form-hint">Handing it over takes the unit out of the default warehouse, as a recorded stock movement.</small>
                        </div>
                    </div>
                    <x-admin.input type="textarea" name="description" label="Description" :value="old('description', $reward->description)" />
                    <x-admin.input type="textarea" name="terms" label="Terms" :value="old('terms', $reward->terms)" />
                    <x-admin.input type="file" name="image" label="Image" accept="image/png,image/jpeg,image/webp" hint="Optional. JPG, PNG or WebP up to 2 MB. Product rewards default to the product image." />
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <x-admin.input type="number" name="points_required" label="Points required" :value="old('points_required', $reward->points_required)" min="1" required />
                    <x-admin.input type="number" name="stock" label="Stock" :value="old('stock', $reward->stock)" min="0" hint="Empty: unlimited." />
                    <x-admin.input type="number" name="per_customer_limit" label="Limit per customer" :value="old('per_customer_limit', $reward->per_customer_limit)" min="1" hint="Empty: no limit." />
                    <x-admin.input type="number" name="total_limit" label="Total redemption limit" :value="old('total_limit', $reward->total_limit)" min="1" hint="Empty: no limit." />
                    <x-admin.input type="datetime-local" name="starts_at" label="Available from" :value="old('starts_at', $reward->starts_at?->format('Y-m-d\TH:i'))" />
                    <x-admin.input type="datetime-local" name="ends_at" label="Available until" :value="old('ends_at', $reward->ends_at?->format('Y-m-d\TH:i'))" />
                    <x-admin.input type="number" name="sort_order" label="Sort order" :value="old('sort_order', $reward->sort_order)" hint="Lower first." />
                    <label class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $reward->is_active))>
                        <span class="form-check-label">Active</span>
                    </label>
                </div>
                <div class="card-footer"><button class="btn btn-primary w-100"><i class="ti ti-device-floppy me-1"></i>Save reward</button></div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const type = document.getElementById('reward-type'), field = document.getElementById('product-field');
    const sync = () => field.classList.toggle('d-none', type.value !== 'product');
    type.addEventListener('change', sync); sync();
})();
</script>
@endpush
