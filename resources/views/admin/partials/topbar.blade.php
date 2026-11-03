@php
    $admin = auth('admin')->user();
    $attentionTotal = array_sum($adminAttention ?? []);
@endphp

<header class="navbar navbar-expand-md d-print-none admin-topbar sticky-top">
    <div class="container-xl">
        <button type="button" class="btn btn-ghost-secondary btn-icon d-none d-lg-inline-flex me-2"
                data-bs-toggle="sidebar-folded" aria-label="Collapse sidebar" title="Collapse / expand sidebar">
            <i class="ti ti-layout-sidebar-left-collapse fs-2"></i>
        </button>

        <form action="{{ route('dashboard.products.index') }}" method="GET" role="search" class="me-auto">
            <div class="input-icon">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" name="search" value="{{ request()->routeIs('dashboard.products.index') ? request('search') : '' }}"
                       class="form-control" placeholder="Search products…" aria-label="Search products">
            </div>
        </form>

        <div class="navbar-nav flex-row order-md-last align-items-center gap-1">
            <div class="nav-item dropdown">
                <a href="#" class="nav-link px-2 position-relative" data-bs-toggle="dropdown" aria-label="Needs attention" title="Needs attention">
                    <i class="ti ti-bell fs-2"></i>
                    @if ($attentionTotal > 0)
                        <span class="badge bg-red badge-notification badge-blink"></span>
                    @endif
                </a>
                <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-end dropdown-menu-card" style="min-width: 18rem">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Needs attention</h3></div>
                        <div class="list-group list-group-flush">
                            <a href="{{ route('dashboard.orders.index', ['status' => 'pending']) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                                <span><i class="ti ti-shopping-cart me-2 text-secondary"></i>Pending orders</span>
                                <span class="badge {{ ($adminAttention['pending_orders'] ?? 0) ? 'bg-orange-lt' : 'bg-secondary-lt' }}">{{ $adminAttention['pending_orders'] ?? 0 }}</span>
                            </a>
                            <a href="{{ route('dashboard.returns.index', ['status' => 'pending']) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                                <span><i class="ti ti-receipt-refund me-2 text-secondary"></i>Pending returns</span>
                                <span class="badge {{ ($adminAttention['pending_returns'] ?? 0) ? 'bg-orange-lt' : 'bg-secondary-lt' }}">{{ $adminAttention['pending_returns'] ?? 0 }}</span>
                            </a>
                            <a href="{{ route('dashboard.inventory.index', ['stock' => 'out']) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                                <span><i class="ti ti-package-off me-2 text-secondary"></i>Out-of-stock sizes</span>
                                <span class="badge {{ ($adminAttention['out_of_stock'] ?? 0) ? 'bg-red-lt' : 'bg-secondary-lt' }}">{{ $adminAttention['out_of_stock'] ?? 0 }}</span>
                            </a>
                            @can('contact_messages.view')
                                <a href="{{ route('dashboard.contacts.index', ['status' => 'open']) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                                    <span><i class="ti ti-mail me-2 text-secondary"></i>Unanswered messages</span>
                                    <span class="badge {{ ($adminAttention['open_contact_messages'] ?? 0) ? 'bg-orange-lt' : 'bg-secondary-lt' }}">{{ $adminAttention['open_contact_messages'] ?? 0 }}</span>
                                </a>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>

            <div class="nav-item dropdown">
                <a href="#" class="nav-link d-flex lh-1 text-reset p-0 ps-2" data-bs-toggle="dropdown" aria-label="Open account menu">
                    <span class="avatar avatar-sm bg-primary-lt">{{ strtoupper(mb_substr($admin?->name ?? 'A', 0, 1)) }}</span>
                    <div class="d-none d-xl-block ps-2">
                        <div class="fw-medium">{{ $admin?->name }}</div>
                        <div class="mt-1 small text-secondary">{{ $admin?->email }}</div>
                    </div>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <div class="dropdown-header">Signed in as administrator</div>
                    <a href="{{ route('dashboard.settings.index') }}" class="dropdown-item"><i class="ti ti-settings me-2"></i>Settings</a>
                    <div class="dropdown-divider"></div>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger"><i class="ti ti-logout me-2"></i>Log out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
