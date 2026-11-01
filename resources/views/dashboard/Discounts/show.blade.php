@extends('admin.layout')

@php
    $state = $promotion->trashed() ? 'archived' : $promotion->state();
    $targetName = fn ($target) => ($lookups[$target->target_type][$target->target_id] ?? null) ?? ucfirst($target->target_type).' #'.$target->target_id;
    $simulationInput ??= [];
    $simLines = $simulationInput['lines'] ?? [['product_size_id' => null, 'quantity' => 1], ['product_size_id' => null, 'quantity' => 1], ['product_size_id' => null, 'quantity' => 1]];
@endphp

@section('title', $promotion->name)
@section('pretitle', 'Marketing · '.\App\Models\DiscountRule::typeLabel($promotion->discount_type).' #'.$promotion->id)

@section('actions')
    <a href="{{ route('dashboard.discounts.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Promotions</a>
    @unless ($promotion->trashed())
        <a href="{{ route('dashboard.discounts.edit', $promotion->id) }}" class="btn btn-outline-primary"><i class="ti ti-edit me-1"></i>Edit</a>
        <form method="POST" action="{{ route('dashboard.discounts.duplicate', $promotion->id) }}">@csrf<button class="btn btn-outline-secondary"><i class="ti ti-copy me-1"></i>Duplicate</button></form>
        <form method="POST" action="{{ route('dashboard.discounts.toggle', $promotion->id) }}">@csrf
            <button class="btn {{ $promotion->is_active ? 'btn-outline-warning' : 'btn-success' }}" @disabled(!$promotion->is_active && $overview['errors'])>{{ $promotion->is_active ? 'Deactivate' : 'Activate' }}</button>
        </form>
    @endunless
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="State" :value="ucfirst($state)" icon="activity" /></div>
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="Orders" :value="$analytics['orders']" icon="shopping-cart" :hint="$analytics['released'].' released (canceled)'" /></div>
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="Counting uses" :value="$analytics['active_uses'].($promotion->usage_limit ? ' / '.$promotion->usage_limit : '')" icon="ticket" /></div>
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="Discount given" :value="number_format($analytics['discount'], 2)" icon="discount" tone="teal" /></div>
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="Gift units" :value="$analytics['gift_units']" icon="gift" tone="purple" /></div>
    <div class="col-sm-6 col-lg-2"><x-admin.stat label="Order revenue" :value="number_format($analytics['revenue'], 2)" icon="cash" tone="green" hint="Orders not canceled" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Configuration</h3><div class="card-actions"><x-admin.status :status="$state" /></div></div>
            <table class="table card-table table-sm">
                <tr><td>Rule</td><td>@include('dashboard.Discounts.partials.rule', ['promotion' => $promotion])</td></tr>
                <tr><td>Valid</td><td>{{ $promotion->startsAt()?->format('Y-m-d H:i') }} → {{ $promotion->endsAt()?->format('Y-m-d H:i') }} <span class="text-secondary small">{{ config('pharmacy.timezone') }}</span></td></tr>
                <tr><td>Campaign</td><td>{{ $promotion->campaign?->name_en ?? '—' }}</td></tr>
                <tr><td>Precedence</td><td>Priority {{ $promotion->priority }} · {{ $promotion->is_exclusive ? 'exclusive' : 'not exclusive' }} · {{ $promotion->is_stackable ? 'stackable' : 'not stackable' }}</td></tr>
                <tr><td>Applies to</td><td>
                    @forelse ($promotion->targets->where('is_gift', false) as $target)
                        <span class="badge {{ $target->is_excluded ? 'bg-red-lt' : 'bg-blue-lt' }} me-1 mb-1">{{ $target->is_excluded ? 'Not ' : '' }}{{ ucfirst($target->target_type) }}: {{ $targetName($target) }}</span>
                    @empty All products @endforelse
                </td></tr>
                <tr><td>Customers</td><td>{{ ucfirst($promotion->customer_scope ?? 'all') }}@if ($promotion->customer_scope === 'specific'): {{ $promotion->customers->pluck('email')->implode(', ') }}@endif</td></tr>
                <tr><td>Limits</td><td>{{ $promotion->usage_limit ? $promotion->usage_limit.' orders' : 'No total limit' }} · {{ $promotion->per_customer_limit ? $promotion->per_customer_limit.' per customer' : 'no per-customer limit' }}</td></tr>
                @if (in_array($promotion->discount_type, ['amount_gift', 'free_delivery'], true))
                    <tr><td>Qualifying spend</td><td class="small">{{ $promotion->qualifying_basis === 'original' ? 'Original prices' : 'Prices after item discounts' }}{{ $promotion->coupon_reduces_qualifying ? ', minus the coupon' : '' }}{{ $promotion->count_delivery_fee ? ', plus delivery' : '' }}. Gift lines never count.@if ($promotion->discount_type === 'amount_gift') Tiers: {{ $promotion->tier_mode }}.@endif</td></tr>
                @endif
                @if (in_array($promotion->discount_type, ['buy_x_get_y', 'amount_gift'], true))
                    <tr><td>Gift out of stock</td><td>{{ ['partial' => 'Give what is in stock', 'skip' => 'Give no gift', 'block' => 'Refuse checkout'][$promotion->gift_fallback] ?? $promotion->gift_fallback }}</td></tr>
                @endif
            </table>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Configuration check</h3></div>
            <div class="card-body">
                @forelse ($overview['errors'] as $error)
                    <div class="text-danger mb-1"><i class="ti ti-alert-octagon me-1"></i>{{ $error }}</div>
                @empty
                    <div class="text-success mb-1"><i class="ti ti-circle-check me-1"></i>No blocking errors.</div>
                @endforelse
                @foreach ($overview['warnings'] as $warning)
                    <div class="text-orange mb-1"><i class="ti ti-alert-triangle me-1"></i>{{ $warning }}</div>
                @endforeach
            </div>
        </div>

        @if ($overview['gifts'])
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Rewards</h3></div>
                <table class="table card-table table-sm">
                    <thead><tr><th>Reward</th><th>Min. spend</th><th>Stock</th></tr></thead>
                    @foreach ($overview['gifts'] as $gift)
                        <tr><td>{{ $gift['label'] }}</td><td>{{ $gift['threshold'] !== null ? number_format((float) $gift['threshold'], 2) : '—' }}</td>
                            <td>@if ($gift['stock'] !== null)<x-admin.status :status="$gift['stock'] > 0 ? 'ok' : 'out'" /> {{ $gift['stock'] }}@else — @endif</td></tr>
                    @endforeach
                </table>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Audit history</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($audit as $entry)
                    <div class="list-group-item small"><span class="fw-medium">{{ $entry->action }}</span> · {{ $entry->admin?->name ?? 'system' }} <div class="text-secondary">{{ $entry->created_at?->format('Y-m-d H:i') }}</div></div>
                @empty
                    <div class="list-group-item small text-secondary">No changes recorded.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Preview</h3></div>
            <form method="POST" action="{{ route('dashboard.discounts.simulate', $promotion->id) }}" class="card-body">
                @csrf
                <p class="text-secondary small">Runs the real pricing engine on this sample cart with this promotion treated as live, together with every other live promotion. Nothing is saved.</p>
                @foreach ($simLines as $i => $line)
                    <div class="row g-2 mb-2">
                        <div class="col-9"><select name="lines[{{ $i }}][product_size_id]" class="form-select form-select-sm" aria-label="Product">
                            <option value="">Choose a product…</option>
                            @foreach ($sizes as $s)<option value="{{ $s->id }}" @selected((int) ($line['product_size_id'] ?? 0) === $s->id)>{{ $s->product?->name_en }} — {{ $s->size }} · {{ number_format((float) $s->price, 2) }} ({{ (int) $s->stock }})</option>@endforeach
                        </select></div>
                        <div class="col-3"><input type="number" min="1" name="lines[{{ $i }}][quantity]" value="{{ $line['quantity'] ?? 1 }}" class="form-control form-control-sm" aria-label="Quantity"></div>
                    </div>
                @endforeach
                <div class="row g-2">
                    <div class="col-md-4"><input type="email" name="customer_email" value="{{ $simulationInput['customer_email'] ?? '' }}" class="form-control form-control-sm" placeholder="Customer email (optional)"></div>
                    <div class="col-md-3"><input type="text" name="coupon" value="{{ $simulationInput['coupon'] ?? '' }}" class="form-control form-control-sm" placeholder="Coupon"></div>
                    <div class="col-md-2"><input type="number" step="0.01" min="0" name="delivery_fee" value="{{ $simulationInput['delivery_fee'] ?? '' }}" class="form-control form-control-sm" placeholder="Delivery"></div>
                    <div class="col-md-3"><input type="datetime-local" name="at" value="{{ $simulationInput['at'] ?? $defaultMoment->format('Y-m-d\TH:i') }}" class="form-control form-control-sm" aria-label="At"></div>
                </div>
                <button class="btn btn-primary btn-sm mt-2"><i class="ti ti-player-play me-1"></i>Run preview</button>
            </form>

            @isset($simulation)
                @php $sim = $simulation->toArray(); @endphp
                <div class="card-body border-top">
                    <table class="table table-sm">
                        <thead><tr><th>Line</th><th>Qty</th><th>Unit</th><th>Final</th><th>Total</th><th>Promotion</th></tr></thead>
                        @foreach ($simulation->lines as $line)
                            <tr><td>{{ $line->product->name_en }} — {{ $line->size->size }}</td><td>{{ $line->quantity }}</td>
                                <td><x-admin.money :amount="$line->originalUnit / 100" /></td><td><x-admin.money :amount="$line->unit / 100" /></td>
                                <td><x-admin.money :amount="$line->total() / 100" /></td>
                                <td class="small">{{ collect($line->applied)->pluck('name')->implode(' + ') ?: '—' }}</td></tr>
                        @endforeach
                        @foreach ($sim['gifts'] as $gift)
                            <tr class="table-success"><td><i class="ti ti-gift me-1"></i>{{ $gift['name'] }} — {{ $gift['size'] }}</td><td>{{ $gift['quantity'] }}@if ($gift['quantity'] < $gift['requested_quantity']) <span class="text-orange small">of {{ $gift['requested_quantity'] }}</span>@endif</td>
                                <td><x-admin.money :amount="$gift['original_price']" /></td><td>0.00</td><td>0.00</td><td class="small">{{ $gift['promotion_name'] }}</td></tr>
                        @endforeach
                    </table>
                    <dl class="row mb-2 small">
                        <dt class="col-7 fw-normal">Original subtotal</dt><dd class="col-5 text-end mb-0"><x-admin.money :amount="$sim['original_subtotal']" /></dd>
                        <dt class="col-7 fw-normal">Item discounts</dt><dd class="col-5 text-end mb-0">− <x-admin.money :amount="$sim['items_discount']" /></dd>
                        <dt class="col-7 fw-normal">Order discounts (automatic)</dt><dd class="col-5 text-end mb-0">− <x-admin.money :amount="$sim['promotion_discount']" /></dd>
                        <dt class="col-7 fw-normal">Coupon</dt><dd class="col-5 text-end mb-0">− <x-admin.money :amount="$sim['coupon_discount']" /></dd>
                        <dt class="col-7 fw-normal">Delivery / delivery discount</dt><dd class="col-5 text-end mb-0"><x-admin.money :amount="$sim['delivery_fee']" /> / − <x-admin.money :amount="$sim['delivery_discount']" /></dd>
                        <dt class="col-7">Total</dt><dd class="col-5 text-end mb-0 fw-bold"><x-admin.money :amount="$sim['total']" /></dd>
                        <dt class="col-7 fw-normal text-teal">Total savings (gifts included)</dt><dd class="col-5 text-end mb-0 text-teal"><x-admin.money :amount="$sim['total_savings']" /></dd>
                    </dl>
                    @if ($sim['applied_promotions'])
                        <div class="small mb-1 fw-medium">Applied</div>
                        @foreach ($sim['applied_promotions'] as $a)<div class="small text-success">✓ {{ $a['name'] }} ({{ $a['type'] }}) — eligible <x-admin.money :amount="$a['eligible_amount']" />, discount <x-admin.money :amount="$a['discount'] + $a['delivery_discount']" />@if ($a['reward_quantity']) , {{ $a['reward_quantity'] }} gift unit(s)@endif</div>@endforeach
                    @endif
                    @if ($sim['rejected_promotions'])
                        <div class="small mt-2 mb-1 fw-medium">Not applied</div>
                        @foreach ($sim['rejected_promotions'] as $r)<div class="small text-orange">✗ {{ $r['name'] }}: {{ $r['message'] }} <span class="text-secondary">({{ $r['reason'] }})</span></div>@endforeach
                    @endif
                    @if ($sim['coupon_rejection'])<div class="small text-orange">✗ Coupon: {{ $sim['coupon_rejection']['message'] }}</div>@endif
                    @foreach ($sim['next_rewards'] as $hint)<div class="small text-blue">→ {{ $hint['name'] }}: {{ isset($hint['missing_amount']) ? 'spend '.number_format($hint['missing_amount'], 2).' more' : 'add '.$hint['missing_quantity'].' more' }}</div>@endforeach
                    @foreach ($sim['blocking'] as $block)<div class="small text-danger">⛔ {{ $block }}</div>@endforeach
                </div>
            @endisset
        </div>

        @if ($overview['sizes'])
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Covered products</h3><div class="card-actions small text-secondary">first 50 · this promotion alone</div></div>
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr><th>Product</th><th>Variant</th><th>Stock</th><th>Price</th><th>Final</th></tr></thead>
                        @foreach ($overview['sizes'] as $row)
                            <tr><td>{{ $row['product'] }}</td><td>{{ $row['size'] }}</td><td>{{ $row['stock'] }}</td><td><x-admin.money :amount="$row['original']" /></td><td class="fw-medium"><x-admin.money :amount="$row['final']" /></td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header"><h3 class="card-title">Usage history</h3></div>
            @if ($redemptions->isEmpty())
                <x-admin.empty icon="receipt-off" title="Not used yet" />
            @else
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Discount</th><th>Gifts</th><th>When</th></tr></thead>
                        @foreach ($redemptions as $r)
                            <tr><td><a href="{{ route('dashboard.orders.show', $r->order_id) }}">#{{ $r->order_id }}</a></td><td class="small">{{ $r->user?->name }}</td>
                                <td><x-admin.status :status="$r->status" /></td><td><x-admin.money :amount="$r->discount_amount" /></td><td>{{ $r->reward_quantity }}</td>
                                <td class="small">{{ $r->created_at?->format('Y-m-d H:i') }}</td></tr>
                        @endforeach
                    </table>
                </div>
                <x-admin.pagination :paginator="$redemptions" />
            @endif
        </div>
    </div>
</div>
@endsection
