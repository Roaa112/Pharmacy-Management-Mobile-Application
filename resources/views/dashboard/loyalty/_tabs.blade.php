@php
    $tabs = [
        ['Overview', 'dashboard.loyalty.overview', 'loyalty.view'],
        ['Customers', 'dashboard.loyalty.customers', 'loyalty.view'],
        ['Transactions', 'dashboard.loyalty.transactions', 'loyalty.view'],
        ['Expiring', 'dashboard.loyalty.expiring', 'loyalty.view'],
        ['Rewards', 'dashboard.loyalty.rewards.index', 'loyalty.view'],
        ['Redemptions', 'dashboard.loyalty.redemptions.index', 'loyalty.view'],
        ['Points per order', 'dashboard.points.index', 'loyalty.view'],
        ['Settings', 'dashboard.loyalty.settings.edit', 'loyalty.settings'],
    ];
@endphp
<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills flex-nowrap overflow-auto" role="tablist">
            @foreach ($tabs as [$label, $route, $permission])
                @can($permission)
                    <li class="nav-item"><a href="{{ route($route) }}" class="nav-link text-nowrap {{ request()->routeIs($route, $route.'*') ? 'active' : '' }}">{{ $label }}</a></li>
                @endcan
            @endforeach
        </ul>
    </div>
</div>
