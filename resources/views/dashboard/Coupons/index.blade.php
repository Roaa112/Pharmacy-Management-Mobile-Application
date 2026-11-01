@extends('admin.layout')

@section('title', 'Coupons')
@section('pretitle', 'Marketing')
@section('subtitle', 'Codes customers enter at checkout. A coupon is reserved when the order is placed, consumed when it completes and given back if it is canceled.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCoupon"><i class="ti ti-plus me-1"></i>New coupon</button>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/libs/tom-select-2.6.2/tom-select.bootstrap5.min.css') }}">
@endpush

@section('content')
<form class="card card-body d-flex flex-row flex-wrap gap-2 mb-3" method="GET">
    <input type="search" name="q" value="{{ request('q') }}" class="form-control w-auto" placeholder="Search code or name" aria-label="Search">
    <select name="status" class="form-select w-auto" aria-label="Status">
        <option value="">Current coupons</option>
        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
        <option value="archived" @selected(request('status') === 'archived')>Archived</option>
    </select>
    <button class="btn btn-outline-primary">Filter</button>
</form>

<div class="card">
    @if ($coupons->isEmpty())
        <x-admin.empty icon="ticket-off" title="No coupons yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Code</th><th>Discount</th><th>State</th><th>Valid</th><th>Usage</th><th>Rules</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($coupons as $coupon)
                    @php $state = $coupon->trashed() ? 'archived' : $coupon->state(); @endphp
                    <tr>
                        <td><a href="{{ route('dashboard.coupons.show', $coupon->id) }}"><code class="fs-4">{{ $coupon->code }}</code></a>@if ($coupon->name)<div class="small text-secondary">{{ $coupon->name }}</div>@endif</td>
                        <td class="fw-medium">{{ rtrim(rtrim($coupon->discount_value, '0'), '.') }}{{ ($coupon->type ?? 'percent') === 'fixed' ? ' '.config('pharmacy.currency') : '%' }}
                            @if ($coupon->min_order_amount)<div class="small text-secondary">Min. order {{ (float) $coupon->min_order_amount }}</div>@endif
                            @if ($coupon->max_discount_amount)<div class="small text-secondary">Max. {{ (float) $coupon->max_discount_amount }}</div>@endif</td>
                        <td><x-admin.status :status="$state" /></td>
                        <td class="small text-nowrap">
                            {{ $coupon->start_at?->format('M j, Y') ?? 'Any time' }}<br>
                            <span class="text-secondary">to {{ $coupon->end_at?->format('M j, Y') ?? 'no end' }}</span>
                        </td>
                        <td style="min-width: 9rem">
                            <div class="small mb-1">
                                {{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }} uses · {{ $coupon->orders_count }} orders
                            </div>
                            @if ($coupon->usage_limit)
                                <div class="progress progress-sm"><div class="progress-bar bg-teal" style="width: {{ min(100, round($coupon->used_count / $coupon->usage_limit * 100)) }}%"></div></div>
                            @endif
                        </td>
                        <td class="small">
                            {{ $coupon->perUserLimit() ? ($coupon->perUserLimit() === 1 ? 'Once per customer' : $coupon->perUserLimit().' per customer') : 'Reusable' }}
                            @if ($coupon->first_order_only)<div>First order only</div>@endif
                            @if ($coupon->customer_scope === 'specific')<div>{{ $coupon->customers->count() }} chosen customer(s)</div>@endif
                            @if ($coupon->targets->isNotEmpty())<div>{{ $coupon->targets->where('is_excluded', false)->count() }} target(s), {{ $coupon->targets->where('is_excluded', true)->count() }} excluded</div>@endif
                            <div class="{{ $coupon->combines_with_promotions ? 'text-teal' : 'text-secondary' }}">{{ $coupon->combines_with_promotions ? 'Combines with promotions' : 'Not on promoted items' }}</div>
                        </td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                @unless ($coupon->trashed())
                                    <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCoupon{{ $coupon->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                    <form method="POST" action="{{ route('dashboard.coupons.toggle', $coupon) }}">@csrf<button class="btn btn-sm btn-ghost-{{ $coupon->is_active ? 'warning' : 'success' }} btn-icon" title="{{ $coupon->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $coupon->is_active ? 'Deactivate' : 'Activate' }}"><i class="ti ti-{{ $coupon->is_active ? 'player-pause' : 'player-play' }}"></i></button></form>
                                    <x-admin.delete :action="route('dashboard.coupons.destroy', $coupon)" confirm="Archive this coupon? Orders that used it keep their totals." />
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$coupons" />
    @endif
</div>
@endsection

@push('modals')
    @foreach ([null, ...$coupons->getCollection()->reject->trashed()] as $coupon)
        @php
            $editing = $coupon !== null;
            $picked = fn ($type, $excluded = false) => $coupon ? $coupon->targets->where('target_type', $type)->where('is_excluded', $excluded)->pluck('target_id')->all() : [];
            $emails = $coupon ? $coupon->customers->pluck('email')->implode("\n") : null;
        @endphp
        <x-admin.modal :id="$editing ? 'editCoupon'.$coupon->id : 'createCoupon'" :title="$editing ? 'Edit coupon '.$coupon->code : 'New coupon'"
                       :action="$editing ? route('dashboard.coupons.update', $coupon->id) : route('dashboard.coupons.store')"
                       :method="$editing ? 'PUT' : 'POST'" :submit="$editing ? 'Save' : 'Create'" size="lg">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="code" label="Code" :value="$coupon?->code" required maxlength="255" />
                <x-admin.input col="col-md-6 mb-3" name="name" label="Internal name" :value="$coupon?->name" maxlength="255" />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="percent" @selected(($coupon?->type ?? 'percent') === 'percent')>Percentage of the order</option>
                        <option value="fixed" @selected($coupon?->type === 'fixed')>Fixed amount</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="number" name="discount_value" label="Discount (% or amount)" :value="$coupon?->discount_value" min="0.01" step="0.01" required hint="A percentage is at most 100." />
                <x-admin.input col="col-md-6 mb-3" type="number" name="min_order_amount" label="Minimum order (eligible items)" :value="$coupon?->min_order_amount" min="0" step="0.01" hint="Empty = no minimum." />
                <x-admin.input col="col-md-6 mb-3" type="number" name="max_discount_amount" label="Maximum discount" :value="$coupon?->max_discount_amount" min="0.01" step="0.01" hint="Empty = no cap." />
                <x-admin.input col="col-md-6 mb-3" type="date" name="start_at" label="Starts" :value="$coupon?->start_at?->toDateString()" required />
                <x-admin.input col="col-md-6 mb-3" type="date" name="end_at" label="Ends" :value="$coupon?->end_at?->toDateString()" required />
                <x-admin.input col="col-md-6 mb-3" type="number" name="usage_limit" label="Total uses allowed" :value="$coupon?->usage_limit" min="1" hint="Leave empty for unlimited." />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Per customer</label>
                    <select name="once_per_user" class="form-select">
                        <option value="0" @selected(!$coupon?->once_per_user)>Can be reused</option>
                        <option value="1" @selected($coupon?->once_per_user)>Once per customer</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="number" name="per_user_limit" label="Uses per customer" :value="$coupon?->per_user_limit" min="1" max="1000" hint="Overrides the option above when set." />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Discounts delivery?</label>
                    <select name="applies_to_delivery" class="form-select">
                        <option value="" @selected($coupon?->applies_to_delivery === null)>Store default (settings)</option>
                        <option value="1" @selected($coupon?->applies_to_delivery === true)>Yes, items and delivery</option>
                        <option value="0" @selected($coupon?->applies_to_delivery === false)>No, items only</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Who can use it</label>
                    <select name="customer_scope" class="form-select">
                        <option value="all" @selected(($coupon?->customer_scope ?? 'all') === 'all')>Every customer</option>
                        <option value="specific" @selected($coupon?->customer_scope === 'specific')>Only the customers listed</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="customer_emails" label="Customer emails" :value="$emails" rows="2" hint="One per line (for “only the customers listed”)." />
                @foreach (['target_categories' => ['Only categories', $categories, 'category', false], 'target_brands' => ['Only brands', $brands, 'brand', false], 'target_products' => ['Only products', $products, 'product', false], 'excluded_products' => ['Never on products', $products, 'product', true]] as $field => [$label, $options, $kind, $excluded])
                    <div class="col-md-6 mb-3">
                        <label class="form-label">{{ $label }}</label>
                        <select name="{{ $field }}[]" class="form-select coupon-picker" multiple placeholder="Any">
                            @foreach ($options as $id => $name)<option value="{{ $id }}" @selected(in_array($id, $picked($kind, $excluded)))>{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <input type="hidden" name="first_order_only" value="0">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="first_order_only" value="1" @checked($coupon?->first_order_only)><span class="form-check-label">First order only</span></label>
            <input type="hidden" name="combines_with_promotions" value="0">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="combines_with_promotions" value="1" @checked($coupon?->combines_with_promotions)><span class="form-check-label">Also on promoted items and with automatic order discounts (stacking)</span></label>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($coupon?->is_active ?? true)>
                <span class="form-check-label">Active</span>
            </label>
        </x-admin.modal>
    @endforeach
@endpush

@push('scripts')
    <script src="{{ asset('assets/libs/tom-select-2.6.2/tom-select.complete.min.js') }}"></script>
    <script>document.querySelectorAll('.coupon-picker').forEach(s => new TomSelect(s, { maxOptions: null, dropdownParent: 'body', plugins: ['remove_button'] }));</script>
@endpush
