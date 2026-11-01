@extends('admin.layout')

@section('title', 'Marketing overview')
@section('pretitle', 'Marketing')
@section('subtitle', 'What is running now, and how each offer performed (from order snapshots; canceled orders excluded).')

@section('actions')
    <form method="GET" class="d-flex gap-2">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" aria-label="From">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" aria-label="To">
        <button class="btn btn-outline-primary">Apply</button>
    </form>
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Live promotions" :value="$kpis['live_promotions']" icon="discount" :hint="$kpis['scheduled'].' scheduled'" :href="route('dashboard.discounts.index', ['status' => 'active'])" /></div>
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Live flash sales" :value="$kpis['live_flash']" icon="bolt" tone="yellow" :href="route('dashboard.flash_sales.index')" /></div>
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Usable coupons" :value="$kpis['active_coupons']" icon="ticket" tone="teal" :hint="$kpis['coupon_uses'].' uses in range'" :href="route('dashboard.coupons.index')" /></div>
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Shown now" :value="$kpis['live_banners'].' banners · '.$kpis['live_ads'].' ads'" icon="photo" tone="purple" /></div>
    <div class="col-sm-6 col-lg-4"><x-admin.stat label="Orders with an offer" :value="$kpis['orders_with_offers'].' / '.$kpis['orders']" icon="shopping-cart" tone="blue" /></div>
    <div class="col-sm-6 col-lg-4"><x-admin.stat label="Customer savings" :value="number_format($kpis['savings'], 2).' '.config('pharmacy.currency')" icon="pig-money" tone="green" hint="Item + order discounts + gift value" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Performance</h3></div>
            @if ($performance->isEmpty())
                <x-admin.empty icon="chart-bar-off" title="No orders used an offer in this range" />
            @else
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead><tr><th>Offer</th><th>Orders</th><th>Eligible sales</th><th>Discount</th><th>Delivery waived</th><th>Gift units</th></tr></thead>
                        @foreach ($performance as $row)
                            <tr>
                                <td>
                                    @if ($row->discount_rule_id)<a href="{{ route('dashboard.discounts.show', $row->discount_rule_id) }}">{{ $row->name }}</a>
                                    @elseif ($row->coupon_id)<a href="{{ route('dashboard.coupons.show', $row->coupon_id) }}"><code>{{ $row->name }}</code></a>
                                    @else {{ $row->name }} @endif
                                    <div class="small text-secondary">{{ str_replace('_', ' ', $row->source) }} · {{ $row->promotion_type }}</div>
                                </td>
                                <td>{{ $row->orders }}</td>
                                <td><x-admin.money :amount="$row->eligible" /></td>
                                <td><x-admin.money :amount="$row->discount" /></td>
                                <td><x-admin.money :amount="$row->delivery_discount" /></td>
                                <td>{{ (int) $row->gift_units }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Ending in 3 days</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($endingSoon as $promotion)
                    <a class="list-group-item list-group-item-action" href="{{ route('dashboard.discounts.show', $promotion->id) }}">{{ $promotion->name }}
                        <div class="small text-secondary">ends {{ $promotion->endsAt()?->format('M j, H:i') }}</div></a>
                @empty
                    <div class="list-group-item small text-secondary">Nothing ends soon.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
