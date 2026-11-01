{{-- One-line summary of a promotion's rule and rewards. --}}
@php $money = fn ($v) => number_format((float) $v, 2).' '.config('pharmacy.currency'); @endphp
@switch($promotion->discount_type)
    @case('percent') {{ rtrim(rtrim((string) $promotion->discount_value, '0'), '.') }}% off @break
    @case('fixed') {{ $money($promotion->discount_value) }} off each @break
    @case('buy_x_get_y')
        Buy {{ $promotion->min_quantity }}{{ $promotion->repeatable ? ' (repeats)' : '' }} →
        {{ $promotion->rewards->map->label()->implode(', ') ?: 'no reward' }}
        @break
    @case('amount_gift')
        @foreach ($promotion->rewards->groupBy(fn ($r) => (string) ($r->threshold_amount ?? $promotion->min_amount)) as $threshold => $rewards)
            <div>Spend {{ $money($threshold) }} → {{ $rewards->map->label()->implode(', ') }}</div>
        @endforeach
        @if ($promotion->rewards->isEmpty()) <span class="text-danger">no reward</span> @endif
        @break
    @case('free_delivery') Free delivery from {{ $money($promotion->min_amount) }} @break
@endswitch
@if ($promotion->min_order_amount) <div class="text-secondary">Order ≥ {{ $money($promotion->min_order_amount) }}</div> @endif
@if ($promotion->customer_scope && $promotion->customer_scope !== 'all') <div class="text-secondary">Customers: {{ $promotion->customer_scope }}</div> @endif
