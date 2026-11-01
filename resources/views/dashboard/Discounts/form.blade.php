@extends('admin.layout')

@php
    $editing = $promotion->exists;
    $type = old('discount_type', $promotion->discount_type);
    $val = fn ($field, $default = null) => old($field, $promotion->{$field} ?? $default);
    $dt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i') : '';
    $targets = old('targets', $promotion->targets->where('is_gift', false)->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id, 'excluded' => $t->is_excluded])->values()->all());
    $rewards = old('rewards', $promotion->rewards->map(fn ($r) => $r->only(['id', 'reward_type', 'threshold_amount', 'product_id', 'product_size_id', 'quantity', 'value', 'max_discount_amount']))->values()->all());
    $emails = old('customer_emails', $promotion->relationLoaded('customers') ? $promotion->customers->pluck('email')->implode("\n") : '');
    $lookupJson = collect($lookups)->map(fn ($items) => collect($items)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values());
@endphp

@section('title', $editing ? 'Edit '.$promotion->name : 'New '.strtolower(\App\Models\DiscountRule::typeLabel($type)))
@section('pretitle', 'Marketing · Promotions')

@section('actions')
    <a href="{{ $editing ? route('dashboard.discounts.show', $promotion->id) : route('dashboard.discounts.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Back</a>
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ $editing ? route('dashboard.discounts.update', $promotion->id) : route('dashboard.discounts.store') }}" id="promotionForm">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Promotion</h3></div>
                <div class="card-body row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required" for="discount_type">Type</label>
                        <select id="discount_type" name="discount_type" class="form-select" @disabled($editing)>
                            @foreach (\App\Models\DiscountRule::TYPES as $option)
                                <option value="{{ $option }}" @selected($type === $option)>{{ \App\Models\DiscountRule::typeLabel($option) }}</option>
                            @endforeach
                        </select>
                        @if ($editing)<input type="hidden" name="discount_type" value="{{ $type }}"><small class="form-hint">The type cannot change; duplicate the promotion instead.</small>@endif
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="campaign_id">Campaign</label>
                        <select id="campaign_id" name="campaign_id" class="form-select">
                            <option value="">None</option>
                            @foreach ($campaigns as $id => $name)<option value="{{ $id }}" @selected((int) $val('campaign_id') === $id)>{{ $name }}</option>@endforeach
                        </select>
                    </div>
                    <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$val('name_en')" maxlength="255" />
                    <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$val('name_ar')" maxlength="255" dir="rtl" />
                    <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_en" label="Description (English)" :value="$val('description_en')" rows="2" />
                    <x-admin.input col="col-md-6 mb-3" type="textarea" name="description_ar" label="Description (Arabic)" :value="$val('description_ar')" rows="2" dir="rtl" />
                    <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="starts_at" label="Starts" :value="$dt($val('starts_at'))" required hint="{{ config('pharmacy.timezone') }} time" />
                    <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="ends_at" label="Ends" :value="$dt($val('ends_at'))" required />
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Rule</h3></div>
                <div class="card-body row">
                    <div class="col-12" data-for="percent fixed">
                        <div class="row">
                            <x-admin.input col="col-md-4 mb-3" type="number" name="discount_value" label="Discount (% or amount per unit)" :value="$val('discount_value')" step="0.01" min="0.01" hint="Percentage: at most 100." />
                            <x-admin.input col="col-md-4 mb-3" type="number" name="max_discount_amount" label="Maximum discount per unit" :value="$val('max_discount_amount')" step="0.01" min="0.01" hint="Empty = no cap." />
                            <x-admin.input col="col-md-4 mb-3" type="number" name="min_quantity" label="Minimum quantity in the line" :value="$val('min_quantity')" min="1" hint="Empty = any quantity." />
                        </div>
                    </div>
                    <div class="col-12" data-for="buy_x_get_y">
                        <div class="row">
                            <x-admin.input col="col-md-4 mb-3" type="number" name="min_quantity" label="Buy quantity (X)" :value="$val('min_quantity', 2)" min="1" required />
                            <x-admin.input col="col-md-4 mb-3" type="number" name="max_applications_per_order" label="Maximum times per order" :value="$val('max_applications_per_order')" min="1" hint="Empty = no limit (when repeatable)." />
                            <div class="col-md-4 mb-3 pt-md-4">
                                <input type="hidden" name="repeatable" value="0">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="repeatable" value="1" @checked($val('repeatable'))><span class="form-check-label">Repeats for every X units</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" data-for="amount_gift free_delivery">
                        <div class="row">
                            <x-admin.input col="col-md-4 mb-3" type="number" name="min_amount" label="Minimum qualifying spend" :value="$val('min_amount')" step="0.01" min="0" hint="Spend X: default threshold for rewards without their own." />
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Qualifying spend uses</label>
                                <select name="qualifying_basis" class="form-select">
                                    <option value="discounted" @selected($val('qualifying_basis') === 'discounted')>Prices after item discounts</option>
                                    <option value="original" @selected($val('qualifying_basis') === 'original')>Original prices</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <input type="hidden" name="coupon_reduces_qualifying" value="0">
                                <label class="form-check mt-md-4"><input class="form-check-input" type="checkbox" name="coupon_reduces_qualifying" value="1" @checked($val('coupon_reduces_qualifying', true))><span class="form-check-label">A coupon reduces the qualifying spend</span></label>
                                <input type="hidden" name="count_delivery_fee" value="0">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="count_delivery_fee" value="1" @checked($val('count_delivery_fee'))><span class="form-check-label">The delivery fee counts</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" data-for="amount_gift">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tiers</label>
                                <select name="tier_mode" class="form-select">
                                    <option value="highest" @selected($val('tier_mode') === 'highest')>Only the highest tier reached</option>
                                    <option value="cumulative" @selected($val('tier_mode') === 'cumulative')>Every tier reached</option>
                                </select>
                            </div>
                            <x-admin.input col="col-md-4 mb-3" type="number" name="max_applications_per_order" label="Maximum times per order" :value="$val('max_applications_per_order')" min="1" hint="Repeatable single tier only." />
                            <div class="col-md-4 mb-3 pt-md-4">
                                <input type="hidden" name="repeatable" value="0">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="repeatable" value="1" @checked($val('repeatable'))><span class="form-check-label">Repeats for every full threshold</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" data-for="free_delivery">
                        <x-admin.input col="col-md-4 mb-3" type="number" name="max_discount_amount" label="Maximum delivery waived" :value="$val('max_discount_amount')" step="0.01" min="0.01" hint="Empty = the whole fee." />
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Applies to</h3>
                    <div class="card-actions"><span class="text-secondary small" data-for="amount_gift free_delivery">No row = every product.</span></div></div>
                <div class="card-body">
                    <table class="table table-sm table-vcenter mb-2" id="targetRows">
                        <thead><tr><th>Kind</th><th>Item</th><th class="w-1">Exclude</th><th class="w-1"></th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addTarget"><i class="ti ti-plus me-1"></i>Add target</button>
                </div>
            </div>

            <div class="card mb-3" data-for="buy_x_get_y amount_gift">
                <div class="card-header"><h3 class="card-title">Rewards</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">Free products are added to the cart automatically as gift lines, at price 0, and removed when the customer stops qualifying. Spend X rewards each have a minimum spend (tier).</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter mb-2" id="rewardRows">
                            <thead><tr><th>Reward</th><th>Min. spend</th><th>Product</th><th>Variant</th><th>Qty</th><th>Value</th><th>Cap</th><th class="w-1"></th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addReward"><i class="ti ti-plus me-1"></i>Add reward</button>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Status and precedence</h3></div>
                <div class="card-body">
                    <input type="hidden" name="is_active" value="0">
                    <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($val('is_active'))><span class="form-check-label">Active</span></label>
                    <small class="form-hint mb-3">Activation is refused while the configuration has errors.</small>
                    <x-admin.input type="number" name="priority" label="Priority" :value="$val('priority', 0)" min="-1000" max="1000" hint="Higher wins. Flash sales always win over discounts." />
                    <input type="hidden" name="is_exclusive" value="0">
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="is_exclusive" value="1" @checked($val('is_exclusive'))><span class="form-check-label">Exclusive (no other offer with it)</span></label>
                    <input type="hidden" name="is_stackable" value="0">
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="is_stackable" value="1" @checked($val('is_stackable'))><span class="form-check-label">Stackable with other stackable offers</span></label>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Conditions and limits</h3></div>
                <div class="card-body">
                    <x-admin.input type="number" name="min_order_amount" label="Minimum cart (original prices)" :value="$val('min_order_amount')" step="0.01" min="0" />
                    <x-admin.input type="number" name="max_order_amount" label="Maximum cart" :value="$val('max_order_amount')" step="0.01" min="0.01" />
                    <x-admin.input type="number" name="usage_limit" label="Total orders allowed" :value="$val('usage_limit')" min="1" hint="Empty = unlimited." />
                    <x-admin.input type="number" name="per_customer_limit" label="Orders per customer" :value="$val('per_customer_limit')" min="1" />
                    <div class="mb-3">
                        <label class="form-label">Customers</label>
                        <select name="customer_scope" class="form-select" id="customer_scope">
                            @foreach (['all' => 'Everyone', 'new' => 'New customers (no order yet)', 'returning' => 'Returning customers', 'specific' => 'Specific customers'] as $scope => $label)
                                <option value="{{ $scope }}" @selected($val('customer_scope', 'all') === $scope)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-admin.input type="textarea" name="customer_emails" label="Customer emails (specific)" :value="$emails" rows="3" hint="One per line." />
                    <div class="mb-3" data-for="buy_x_get_y amount_gift">
                        <label class="form-label">When a gift is out of stock</label>
                        <select name="gift_fallback" class="form-select">
                            <option value="partial" @selected($val('gift_fallback') === 'partial')>Give what is in stock</option>
                            <option value="skip" @selected($val('gift_fallback') === 'skip')>Give no gift</option>
                            <option value="block" @selected($val('gift_fallback') === 'block')>Refuse checkout until reviewed</option>
                        </select>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary w-100">{{ $editing ? 'Save' : 'Create promotion' }}</button>
        </div>
    </div>
</form>

<template id="targetTemplate">
    <tr>
        <td><select class="form-select form-select-sm" data-name="type" aria-label="Kind">
            <option value="product">Product</option><option value="variant">Variant</option><option value="brand">Brand</option><option value="category">Category</option>
        </select></td>
        <td><select class="form-select form-select-sm" data-name="id" aria-label="Item"></select></td>
        <td><input type="checkbox" class="form-check-input" data-name="excluded" value="1" aria-label="Exclude"></td>
        <td><button type="button" class="btn btn-sm btn-ghost-danger btn-icon" data-remove aria-label="Remove"><i class="ti ti-x"></i></button></td>
    </tr>
</template>
<template id="rewardTemplate">
    <tr>
        <td><input type="hidden" data-name="id"><select class="form-select form-select-sm" data-name="reward_type" aria-label="Reward">
            <option value="free_product">Free product</option><option value="fixed_discount">Fixed discount</option><option value="percent_discount">Percentage discount</option><option value="free_delivery">Free delivery</option>
        </select></td>
        <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" data-name="threshold_amount" style="width:7rem" aria-label="Minimum spend"></td>
        <td><select class="form-select form-select-sm" data-name="product_id" aria-label="Product"><option value="">—</option>
            @foreach ($lookups['product'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></td>
        <td><select class="form-select form-select-sm" data-name="product_size_id" aria-label="Variant"><option value="">First in stock</option>
            @foreach ($sizes as $s)<option value="{{ $s->id }}" data-product="{{ $s->product_id }}">{{ $s->size }} ({{ (int) $s->stock }} in stock)</option>@endforeach</select></td>
        <td><input type="number" min="1" max="100" class="form-control form-control-sm" data-name="quantity" value="1" style="width:4.5rem" aria-label="Quantity"></td>
        <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" data-name="value" style="width:6rem" aria-label="Value"></td>
        <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" data-name="max_discount_amount" style="width:6rem" aria-label="Cap"></td>
        <td><button type="button" class="btn btn-sm btn-ghost-danger btn-icon" data-remove aria-label="Remove"><i class="ti ti-x"></i></button></td>
    </tr>
</template>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('promotionForm');
    const type = form.querySelector('#discount_type');
    const lookups = @json($lookupJson);
    let targetIndex = 0, rewardIndex = 0;

    // Only the visible sections' inputs are enabled, so hidden ones are not submitted.
    function toggleSections() {
        document.querySelectorAll('[data-for]').forEach(section => {
            const on = section.dataset.for.split(' ').includes(type.value);
            section.classList.toggle('d-none', !on);
            section.querySelectorAll('input, select, textarea').forEach(el => el.disabled = !on);
        });
    }

    function fillOptions(select, kind, selected) {
        select.innerHTML = '';
        (lookups[kind] || []).forEach(item => {
            const option = new Option(item.name, item.id, false, String(item.id) === String(selected));
            select.add(option);
        });
    }

    function addTarget(data) {
        const row = document.getElementById('targetTemplate').content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(el => el.name = 'targets[' + targetIndex + '][' + el.dataset.name + ']');
        const kind = row.querySelector('[data-name="type"]'), item = row.querySelector('[data-name="id"]');
        kind.value = data.type || 'product';
        fillOptions(item, kind.value, data.id);
        row.querySelector('[data-name="excluded"]').checked = !!(data.excluded && data.excluded !== '0');
        kind.addEventListener('change', () => fillOptions(item, kind.value));
        row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
        document.querySelector('#targetRows tbody').appendChild(row);
        targetIndex++;
    }

    function addReward(data) {
        const row = document.getElementById('rewardTemplate').content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(el => {
            el.name = 'rewards[' + rewardIndex + '][' + el.dataset.name + ']';
            if (data[el.dataset.name] !== undefined && data[el.dataset.name] !== null) el.value = data[el.dataset.name];
        });
        const product = row.querySelector('[data-name="product_id"]'), size = row.querySelector('[data-name="product_size_id"]');
        const filterSizes = () => size.querySelectorAll('option[data-product]').forEach(o => o.hidden = o.dataset.product !== product.value);
        product.addEventListener('change', () => { size.value = ''; filterSizes(); });
        filterSizes();
        row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
        document.querySelector('#rewardRows tbody').appendChild(row);
        rewardIndex++;
    }

    (@json(array_values($targets))).forEach(addTarget);
    (@json(array_values($rewards))).forEach(addReward);
    document.getElementById('addTarget').addEventListener('click', () => addTarget({}));
    document.getElementById('addReward').addEventListener('click', () => addReward({ reward_type: 'free_product', quantity: 1 }));
    type.addEventListener('change', toggleSections);
    toggleSections();
})();
</script>
@endpush
