@extends('admin.layout')

@section('title', 'Coupon '.$coupon->code)
@section('pretitle', 'Marketing · Coupons')

@section('actions')
    <a href="{{ route('dashboard.coupons.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Coupons</a>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-4"><x-admin.stat label="Counting uses" :value="$stats['counting'].($coupon->usage_limit ? ' / '.$coupon->usage_limit : '')" icon="ticket" /></div>
    <div class="col-sm-4"><x-admin.stat label="Released (canceled orders)" :value="$stats['released']" icon="arrow-back-up" tone="secondary" /></div>
    <div class="col-sm-4"><x-admin.stat label="Discount given" :value="number_format($stats['discount'], 2).' '.config('pharmacy.currency')" icon="discount" tone="teal" /></div>
</div>
<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Rules</h3><div class="card-actions"><x-admin.status :status="$coupon->trashed() ? 'archived' : $coupon->state()" /></div></div>
            <table class="table card-table table-sm">
                <tr><td>Discount</td><td>{{ (float) $coupon->discount_value }}{{ $coupon->type === 'fixed' ? ' '.config('pharmacy.currency') : '%' }}{{ $coupon->max_discount_amount ? ', at most '.(float) $coupon->max_discount_amount : '' }}</td></tr>
                <tr><td>Valid</td><td>{{ $coupon->start_at?->format('Y-m-d H:i') ?? 'any time' }} → {{ $coupon->end_at?->format('Y-m-d H:i') ?? 'no end' }}</td></tr>
                <tr><td>Minimum</td><td>{{ $coupon->min_order_amount ? (float) $coupon->min_order_amount.' of eligible items' : 'none' }}</td></tr>
                <tr><td>Limits</td><td>{{ $coupon->usage_limit ?? 'unlimited' }} total · {{ $coupon->perUserLimit() ?? 'unlimited' }} per customer{{ $coupon->first_order_only ? ' · first order only' : '' }}</td></tr>
                <tr><td>Customers</td><td>{{ $coupon->customer_scope === 'specific' ? $coupon->customers->pluck('email')->implode(', ') : 'Everyone' }}</td></tr>
                <tr><td>Items</td><td>
                    @forelse ($coupon->targets as $target)
                        <span class="badge {{ $target->is_excluded ? 'bg-red-lt' : 'bg-blue-lt' }} me-1">{{ $target->is_excluded ? 'Not ' : '' }}{{ ucfirst($target->target_type) }}: {{ $names[$target->target_type][$target->target_id] ?? '#'.$target->target_id }}</span>
                    @empty Every item @endforelse
                </td></tr>
                <tr><td>Stacking</td><td>{{ $coupon->combines_with_promotions ? 'Also on promoted items and with automatic order discounts' : 'Only items without a promotion; competes with automatic order discounts (the larger applies)' }}</td></tr>
                <tr><td>Delivery</td><td>{{ $coupon->applies_to_delivery === null ? 'Store default' : ($coupon->applies_to_delivery ? 'Discounts delivery too' : 'Items only') }}</td></tr>
            </table>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Audit history</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($audit as $entry)
                    <div class="list-group-item small"><span class="fw-medium">{{ $entry->action }}</span> · {{ $entry->admin?->name ?? 'system' }}<div class="text-secondary">{{ $entry->created_at?->format('Y-m-d H:i') }}</div></div>
                @empty
                    <div class="list-group-item small text-secondary">No changes recorded.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Redemptions</h3></div>
            @if ($redemptions->isEmpty())
                <x-admin.empty icon="receipt-off" title="Not used yet" />
            @else
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Discount</th><th>Reserved</th><th>Consumed / released</th></tr></thead>
                        @foreach ($redemptions as $r)
                            <tr><td><a href="{{ route('dashboard.orders.show', $r->order_id) }}">#{{ $r->order_id }}</a></td><td class="small">{{ $r->user?->name }}</td>
                                <td><x-admin.status :status="$r->status" /></td><td><x-admin.money :amount="$r->discount_amount" /></td>
                                <td class="small">{{ $r->reserved_at?->format('Y-m-d H:i') }}</td><td class="small">{{ ($r->consumed_at ?? $r->released_at)?->format('Y-m-d H:i') ?? '—' }}</td></tr>
                        @endforeach
                    </table>
                </div>
                <x-admin.pagination :paginator="$redemptions" />
            @endif
        </div>
    </div>
</div>
@endsection
