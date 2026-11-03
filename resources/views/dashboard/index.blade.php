@extends('admin.layout')

@section('title', 'Dashboard')
@section('pretitle', 'Overview')
@section('subtitle', 'Store performance across all orders. Revenue counts completed orders that were not refunded.')

@section('actions')
    <a href="{{ route('dashboard.reports.index') }}" class="btn btn-outline-primary"><i class="ti ti-chart-bar me-1"></i>Reports</a>
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-sm-6 col-xl-3">
        <x-admin.stat label="Revenue" icon="cash" tone="teal" :value="number_format((float) $stats['revenue'], 2).' '.config('pharmacy.currency')"
                      :hint="$stats['completed_orders'].' completed orders'" :href="route('dashboard.reports.index')" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-admin.stat label="Orders" icon="shopping-cart" :value="number_format($stats['orders'])"
                      :hint="$stats['pending_orders'].' waiting for review'" :href="route('dashboard.orders.index')" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-admin.stat label="Customers" icon="users" tone="azure" :value="number_format($stats['customers'])"
                      hint="Registered app users" :href="route('dashboard.customers.index')" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-admin.stat label="Products" icon="pill" tone="purple" :value="number_format($stats['products'])"
                      hint="In the catalog" :href="route('dashboard.products.index')" />
    </div>
    <div class="col-sm-6 col-xl-4">
        <x-admin.stat label="Low stock sizes" icon="alert-triangle" tone="orange" :value="number_format($stats['low_stock'])"
                      :hint="'1 – '.$lowStock.' units left'" :href="route('dashboard.inventory.index', ['stock' => 'low'])" />
    </div>
    <div class="col-sm-6 col-xl-4">
        <x-admin.stat label="Out of stock sizes" icon="package-off" tone="red" :value="number_format($stats['out_of_stock'])"
                      hint="Shown as out of stock in the app" :href="route('dashboard.inventory.index', ['stock' => 'out'])" />
    </div>
    <div class="col-sm-12 col-xl-4">
        <x-admin.stat label="Pending returns" icon="receipt-refund" tone="yellow" :value="number_format($stats['pending_returns'])"
                      hint="Waiting for a decision" :href="route('dashboard.returns.index', ['status' => 'pending'])" />
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Revenue</h3>
                    <div class="card-subtitle">Completed, non-refunded orders · last 30 days by order date</div>
                </div>
            </div>
            <div class="card-body">
                @if (array_sum($trend['revenue']) > 0)
                    <div class="chart-box"><canvas id="revenueChart" aria-label="Revenue per day" role="img"></canvas></div>
                @else
                    <x-admin.empty icon="chart-line" title="No completed sales in the last 30 days" />
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Orders</h3>
                    <div class="card-subtitle">All statuses · last 30 days</div>
                </div>
            </div>
            <div class="card-body">
                @if (array_sum($trend['orders']) > 0)
                    <div class="chart-box"><canvas id="ordersChart" aria-label="Orders per day" role="img"></canvas></div>
                @else
                    <x-admin.empty icon="shopping-cart-off" title="No orders in the last 30 days" />
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Recent orders</h3>
                <div class="card-actions"><a href="{{ route('dashboard.orders.index') }}" class="btn btn-sm btn-ghost-primary">View all</a></div>
            </div>
            @if ($recentOrders->isEmpty())
                <x-admin.empty icon="shopping-cart-off" title="No orders yet" />
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        @foreach ($recentOrders as $order)
                            <tr>
                                <td><a href="{{ route('dashboard.orders.show', $order) }}" class="fw-medium">#{{ $order->id }}</a>
                                    <div class="small text-secondary">{{ $order->created_at->diffForHumans() }}</div></td>
                                <td class="text-truncate" style="max-width: 10rem">{{ $order->user->name ?? '—' }}</td>
                                <td><x-admin.status :status="$order->status" /></td>
                                <td class="text-end"><x-admin.money :amount="$order->total_price" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Top sellers</h3></div>
            @if ($topProducts->isEmpty())
                <x-admin.empty icon="trophy-off" title="No sales yet" />
            @else
                <div class="list-group list-group-flush">
                    @foreach ($topProducts as $row)
                        <div class="list-group-item d-flex align-items-center gap-2">
                            <x-admin.thumb :src="$row->product?->image ? asset($row->product->image) : null" />
                            <div class="min-w-0 flex-fill">
                                <div class="text-truncate fw-medium">{{ $row->product->name_en ?? 'Deleted product' }}</div>
                                <div class="small text-secondary">{{ number_format($row->units) }} units</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Low stock</h3>
                <div class="card-actions"><a href="{{ route('dashboard.inventory.index', ['stock' => 'low']) }}" class="btn btn-sm btn-ghost-primary">Inventory</a></div>
            </div>
            @if ($lowStockSizes->isEmpty())
                <x-admin.empty icon="circle-check" title="Stock levels look healthy" />
            @else
                <div class="list-group list-group-flush">
                    @foreach ($lowStockSizes as $size)
                        <div class="list-group-item d-flex align-items-center justify-content-between gap-2">
                            <div class="min-w-0">
                                <div class="text-truncate fw-medium">{{ $size->product->name_en ?? '—' }}</div>
                                <div class="small text-secondary">{{ $size->size }}</div>
                            </div>
                            <span class="badge {{ $size->stock <= 0 ? 'bg-red-lt' : 'bg-orange-lt' }}">{{ $size->stock }} left</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/libs/chartjs-4.5.1/chart.umd.min.js') }}"></script>
    <script>
        (function () {
            const trend = @json($trend);
            const primary = getComputedStyle(document.documentElement).getPropertyValue('--tblr-primary').trim() || '#1d4ed8';
            const teal = getComputedStyle(document.documentElement).getPropertyValue('--tblr-teal').trim() || '#0d9488';
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
            Chart.defaults.color = '#64748b';
            const common = {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: '#eef2f7' } } },
            };

            const revenue = document.getElementById('revenueChart');
            if (revenue) {
                new Chart(revenue, {
                    type: 'line',
                    data: { labels: trend.labels, datasets: [{ data: trend.revenue, borderColor: teal, backgroundColor: teal + '22', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
                    options: common,
                });
            }

            const orders = document.getElementById('ordersChart');
            if (orders) {
                new Chart(orders, {
                    type: 'bar',
                    data: { labels: trend.labels, datasets: [{ data: trend.orders, backgroundColor: primary, borderRadius: 4, maxBarThickness: 14 }] },
                    options: { ...common, scales: { ...common.scales, y: { ...common.scales.y, ticks: { precision: 0 } } } },
                });
            }
        })();
    </script>
@endpush
