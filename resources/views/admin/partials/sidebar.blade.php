@php
    // [label, route name, icon, route patterns that mark the item active, badge]
    $sections = [
        'Overview' => [
            ['Dashboard', 'dashboard.home', 'layout-dashboard', ['dashboard.home']],
            ['Reports', 'dashboard.reports.index', 'chart-bar', ['dashboard.reports.*']],
        ],
        'Sales' => [
            ['Orders', 'dashboard.orders.index', 'shopping-cart', ['dashboard.orders.*'], $adminAttention['pending_orders'] ?? 0],
            ['Returns', 'dashboard.returns.index', 'receipt-refund', ['dashboard.returns.*'], $adminAttention['pending_returns'] ?? 0],
            ['Customers', 'dashboard.customers.index', 'users', ['dashboard.customers.*']],
            ['Loyalty points', 'dashboard.points.index', 'star', ['dashboard.points.*']],
        ],
        'Catalog' => [
            ['Products', 'dashboard.products.index', 'pill', ['dashboard.products.*']],
            ['Categories', 'dashboard.categories.index', 'category', ['dashboard.categories.*']],
            ['Brands', 'dashboard.brands.index', 'badge-tm', ['dashboard.brands.*']],
            ['Health issues', 'dashboard.health_issues.index', 'stethoscope', ['dashboard.health_issues.*']],
        ],
        'Inventory' => [
            ['Stock overview', 'dashboard.inventory.index', 'packages', ['dashboard.inventory.index'], $adminAttention['out_of_stock'] ?? 0],
            ['Stock movements', 'dashboard.inventory.movements', 'arrows-exchange', ['dashboard.inventory.movements']],
        ],
        'Marketing' => [
            ['Coupons', 'dashboard.coupons.index', 'ticket', ['dashboard.coupons.*']],
            ['Discounts', 'dashboard.discounts.index', 'discount', ['dashboard.discounts.*']],
            ['Flash sales', 'dashboard.flash_sales.index', 'bolt', ['dashboard.flash_sales.*']],
            ['Banners', 'dashboard.banners.index', 'photo', ['dashboard.banners.*']],
            ['Opening ads', 'dashboard.OpeningAd.index', 'device-mobile', ['dashboard.OpeningAd.*']],
        ],
        'Operations' => [
            ['Delivery prices', 'dashboard.delivery_prices.index', 'truck-delivery', ['dashboard.delivery_prices.*']],
            ['Contact messages', 'dashboard.contacts.index', 'mail', ['dashboard.contacts.*']],
            ['Settings', 'dashboard.settings.index', 'settings', ['dashboard.settings.*']],
        ],
    ];

    $healthLinks = [
        ['Body weight', 'admin.health.body_weight'],
        ['Blood sugar', 'admin.health.blood_sugar'],
        ['Blood pressure', 'admin.health.blood_pressure'],
        ['Ovulation', 'admin.health.ovulation'],
        ['Pregnancy', 'admin.health.pregnancy'],
        ['Children & vaccines', 'admin.health.children'],
    ];
    $healthActive = request()->routeIs('admin.health.*');
@endphp

<aside class="navbar navbar-vertical navbar-expand-lg admin-sidebar" data-bs-theme="dark">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu"
                aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <a href="{{ route('dashboard.home') }}" class="navbar-brand navbar-brand-autodark text-white text-decoration-none">
            <span class="brand-mark"><i class="ti ti-first-aid-kit"></i></span>
            <span class="brand-text fw-bold">{{ config('pharmacy.name') }}<small>Pharmacy admin</small></span>
        </a>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2 pb-3">
                @foreach ($sections as $section => $items)
                    <li class="nav-section">{{ $section }}</li>
                    @foreach ($items as $item)
                        @php [$label, $route, $icon, $patterns] = $item; $badge = $item[4] ?? 0; @endphp
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs(...$patterns) ? 'active' : '' }}" href="{{ route($route) }}"
                               title="{{ $label }}" @if (request()->routeIs(...$patterns)) aria-current="page" @endif>
                                <span class="nav-link-icon"><i class="ti ti-{{ $icon }} fs-2"></i></span>
                                <span class="nav-link-title">{{ $label }}</span>
                                @if ($badge > 0)
                                    <span class="badge bg-orange text-white ms-auto">{{ $badge }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach

                    @if ($section === 'Sales')
                        {{-- Health data is personal and sensitive: grouped so it is easy to restrict later. --}}
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle {{ $healthActive ? 'active show' : '' }}" href="#sidebar-health"
                               data-bs-toggle="dropdown" data-bs-auto-close="false" role="button"
                               aria-expanded="{{ $healthActive ? 'true' : 'false' }}" title="Customer health data">
                                <span class="nav-link-icon"><i class="ti ti-heart-rate-monitor fs-2"></i></span>
                                <span class="nav-link-title">Health data</span>
                            </a>
                            <div class="dropdown-menu {{ $healthActive ? 'show' : '' }}">
                                @foreach ($healthLinks as [$label, $route])
                                    <a class="dropdown-item {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}">{{ $label }}</a>
                                @endforeach
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </div>
</aside>
