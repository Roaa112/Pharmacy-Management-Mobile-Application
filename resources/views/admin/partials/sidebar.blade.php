@php
    // [label, route name, icon, route patterns that mark the item active, badge, permission]
    // Items the admin's role does not allow are not shown (the routes refuse them anyway).
    $sections = [
        'Overview' => [
            ['Dashboard', 'dashboard.home', 'layout-dashboard', ['dashboard.home'], 0, null],
            ['Reports', 'dashboard.reports.index', 'chart-bar', ['dashboard.reports.*'], 0, 'reports'],
        ],
        'Sales' => [
            ['Orders', 'dashboard.orders.index', 'shopping-cart', ['dashboard.orders.*'], $adminAttention['pending_orders'] ?? 0, 'orders'],
            ['Returns', 'dashboard.returns.index', 'receipt-refund', ['dashboard.returns.*'], $adminAttention['pending_returns'] ?? 0, 'refunds'],
            ['Customers', 'dashboard.customers.index', 'users', ['dashboard.customers.*'], 0, 'users'],
            ['Loyalty', 'dashboard.loyalty.overview', 'star', ['dashboard.loyalty.overview', 'dashboard.loyalty.customers', 'dashboard.loyalty.transactions', 'dashboard.loyalty.expiring', 'dashboard.loyalty.settings.*', 'dashboard.points.*'], 0, 'loyalty'],
            ['Rewards', 'dashboard.loyalty.rewards.index', 'gift', ['dashboard.loyalty.rewards.*', 'dashboard.loyalty.redemptions.*'], $adminAttention['pending_redemptions'] ?? 0, 'loyalty'],
        ],
        'Payments' => [
            ['Payments', 'dashboard.payments.index', 'credit-card', ['dashboard.payments.*'], $adminAttention['payments_to_verify'] ?? 0, 'payments'],
            ['Refunds', 'dashboard.refunds.index', 'receipt-refund', ['dashboard.refunds.*'], $adminAttention['pending_refunds'] ?? 0, 'refunds'],
            ['Cash on delivery', 'dashboard.cod.index', 'cash', ['dashboard.cod.*'], 0, 'payments'],
            ['Payment methods', 'dashboard.payment_methods.index', 'wallet', ['dashboard.payment_methods.*'], 0, 'payments'],
        ],
        'Delivery' => [
            ['Shipments', 'dashboard.delivery.shipments.index', 'truck', ['dashboard.delivery.shipments.*'], $adminAttention['shipments_attention'] ?? 0, 'delivery'],
            ['Delivery zones', 'dashboard.delivery.zones.index', 'truck-delivery', ['dashboard.delivery.zones.*', 'dashboard.delivery_prices.*'], 0, 'delivery'],
            ['Providers', 'dashboard.delivery.providers.index', 'building-warehouse', ['dashboard.delivery.providers.*'], 0, 'delivery'],
            ['Cities & areas', 'dashboard.cities.index', 'map-2', ['dashboard.cities.*'], 0, 'delivery'],
        ],
        'Catalog' => [
            ['Products', 'dashboard.products.index', 'pill', ['dashboard.products.*'], 0, 'catalog'],
            ['Categories', 'dashboard.categories.index', 'category', ['dashboard.categories.*'], 0, 'categories.view'],
            ['Brands', 'dashboard.brands.index', 'badge-tm', ['dashboard.brands.*'], 0, 'brands.view'],
            ['Health issues', 'dashboard.health_issues.index', 'stethoscope', ['dashboard.health_issues.*'], 0, 'health_issues.view'],
        ],
        'Inventory' => [
            ['Stock overview', 'dashboard.inventory.index', 'packages', ['dashboard.inventory.index', 'dashboard.inventory.show'], $adminAttention['out_of_stock'] ?? 0, 'inventory'],
            ['Stock movements', 'dashboard.inventory.movements', 'arrows-exchange', ['dashboard.inventory.movements'], 0, 'inventory'],
            ['Transfers', 'dashboard.transfers.index', 'arrows-right-left', ['dashboard.transfers.*'], 0, 'inventory'],
            ['Stock counts', 'dashboard.counts.index', 'clipboard-list', ['dashboard.counts.*'], 0, 'inventory'],
            ['Warehouses', 'dashboard.warehouses.index', 'building-warehouse', ['dashboard.warehouses.*'], 0, 'inventory'],
            ['Inventory reports', 'dashboard.inventory.reports', 'report-analytics', ['dashboard.inventory.reports'], 0, 'inventory'],
        ],
        'Purchasing' => [
            ['Purchase orders', 'dashboard.purchase_orders.index', 'file-invoice', ['dashboard.purchase_orders.*'], 0, 'purchasing'],
            ['Suppliers', 'dashboard.suppliers.index', 'truck-loading', ['dashboard.suppliers.*'], 0, 'purchasing'],
        ],
        'Marketing' => [
            ['Marketing overview', 'dashboard.marketing.index', 'chart-pie', ['dashboard.marketing.*'], 0, 'marketing'],
            ['Promotions', 'dashboard.discounts.index', 'discount', ['dashboard.discounts.*'], 0, 'marketing'],
            ['Campaigns', 'dashboard.campaigns.index', 'speakerphone', ['dashboard.campaigns.*'], 0, 'marketing'],
            ['Coupons', 'dashboard.coupons.index', 'ticket', ['dashboard.coupons.*'], 0, 'marketing'],
            ['Flash sales', 'dashboard.flash_sales.index', 'bolt', ['dashboard.flash_sales.*'], 0, 'marketing'],
            ['Banners', 'dashboard.banners.index', 'photo', ['dashboard.banners.*'], 0, 'marketing'],
            ['Opening ads', 'dashboard.OpeningAd.index', 'device-mobile', ['dashboard.OpeningAd.*'], 0, 'marketing'],
        ],
        'Health content' => [
            ['Vaccination schedule', 'dashboard.health_content.vaccines.index', 'vaccine', ['dashboard.health_content.vaccines.*'], 0, 'health_content'],
            ['Pregnancy weeks', 'dashboard.health_content.pregnancy.index', 'baby-carriage', ['dashboard.health_content.pregnancy.*'], 0, 'health_content'],
        ],
        'Operations' => [
            ['Contact messages', 'dashboard.contacts.index', 'mail', ['dashboard.contacts.*'], 0, 'contact_messages.view'],
            ['Settings', 'dashboard.settings.index', 'settings', ['dashboard.settings.*'], 0, 'settings'],
            ['Admins & roles', 'dashboard.admins.index', 'shield-lock', ['dashboard.admins.*', 'dashboard.audit.*'], 0, 'admins'],
            ['Roles & permissions', 'dashboard.roles.index', 'key', ['dashboard.roles.*'], 0, 'roles.view'],
        ],
    ];
    $sections = array_filter(array_map(
        fn ($items) => array_values(array_filter($items, fn ($item) => $item[5] === null || \Illuminate\Support\Facades\Gate::allows($item[5]))),
        $sections,
    ));

    $healthLinks = [
        ['Body weight', 'admin.health.body_weight'],
        ['Blood sugar', 'admin.health.blood_sugar'],
        ['Blood pressure', 'admin.health.blood_pressure'],
        ['Ovulation', 'admin.health.ovulation'],
        ['Pregnancy', 'admin.health.pregnancy'],
        ['Children & vaccines', 'admin.health.children'],
        ['Reminders sent', 'admin.health.notifications'],
    ];
    $healthActive = request()->routeIs('admin.health.*');
    $canSeeHealth = \Illuminate\Support\Facades\Gate::allows('health_data');
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

                    @if ($section === 'Sales' && $canSeeHealth)
                        {{-- Health data is personal and sensitive: only for the health_data permission. --}}
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
