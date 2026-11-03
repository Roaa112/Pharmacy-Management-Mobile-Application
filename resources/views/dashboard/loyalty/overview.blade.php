@extends('admin.layout')

@section('title', 'Loyalty programme')
@section('pretitle', 'Sales')
@section('subtitle', 'Points earned on orders, spent on rewards or at checkout, and their expiry. Every number comes from the points ledger.')

@section('actions')
    @can('loyalty.manage_rewards')<a href="{{ route('dashboard.loyalty.rewards.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New reward</a>@endcan
@endsection

@section('content')
@include('dashboard.loyalty._tabs')

<div class="row row-cards mb-3">
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Active points" icon="star" tone="yellow" :value="number_format($stats['active'])" :hint="number_format($stats['pending']).' pending'" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Points earned" icon="trending-up" tone="green" :value="number_format($stats['earned'])" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Points redeemed" icon="gift" tone="blue" :value="number_format($stats['redeemed'])" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Points expired" icon="clock-x" tone="secondary" :value="number_format($stats['expired'])" :hint="number_format($stats['expiring']).' expire within '.$warningDays.' days'" :href="route('dashboard.loyalty.expiring')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Points reversed" icon="receipt-refund" tone="red" :value="number_format($stats['reversed'])" hint="Cancellations and refunds" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Rewards" icon="gift-card" tone="purple" :value="number_format($stats['rewards'])" :hint="$stats['rewards_active'].' available now'" :href="route('dashboard.loyalty.rewards.index')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Redemptions" icon="ticket" tone="cyan" :value="number_format($stats['redemptions'])" :hint="$stats['redemptions_open'].' waiting to be handed over'" :href="route('dashboard.loyalty.redemptions.index')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Earning rule" icon="calculator" :value="$settings->pointsPerSpend().' / '.number_format($settings->spendMinor() / 100, 2)" :hint="$settings->enabled() ? 'Points per amount spent' : 'Programme paused'" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Latest transactions</h3><div class="card-actions"><a href="{{ route('dashboard.loyalty.transactions') }}">All</a></div></div>
            @include('dashboard.loyalty._entries', ['entries' => $recent, 'showCustomer' => true])
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Top balances</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($topCustomers as $row)
                    <a class="list-group-item list-group-item-action d-flex" href="{{ route('dashboard.customers.show', $row->user_id) }}">
                        <span>{{ $row->user?->name ?? '#'.$row->user_id }}<span class="d-block small text-secondary">{{ $row->user?->email }}</span></span>
                        <span class="ms-auto badge bg-yellow-lt align-self-center">{{ number_format($row->balance) }}</span>
                    </a>
                @empty
                    <div class="list-group-item text-secondary">No customer has usable points yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
