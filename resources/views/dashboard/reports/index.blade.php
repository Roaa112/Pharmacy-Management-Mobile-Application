@extends('admin.layout')

@php
    $statusLabels = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'on_delivery' => 'On delivery', 'completed' => 'Completed', 'canceled' => 'Canceled'];
    $money = fn ($v) => number_format((float) $v, 2).' '.config('pharmacy.currency');
@endphp

@section('title', 'Reports')
@section('pretitle', 'Overview')
@section('subtitle', 'Revenue counts completed orders without an accepted return, by order date.')

@section('actions')
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" aria-label="From" style="width: auto">
        <span class="text-secondary">to</span>
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" aria-label="To" style="width: auto">
        <button class="btn btn-primary">Apply</button>
    </form>
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Revenue" icon="cash" tone="teal" :value="$money($summary['revenue'])" :hint="$summary['completed'].' completed orders'" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Average order" icon="receipt" :value="$money($summary['average_order'])" hint="Completed orders" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Orders placed" icon="shopping-cart" tone="azure" :value="number_format($summary['orders'])" :hint="$summary['canceled'].' canceled'" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="New customers" icon="user-plus" tone="purple" :value="number_format($summary['new_customers'])" /></div>

    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Daily revenue</h3></div>
            <div class="card-body">
                @if (array_sum($daily['revenue']) > 0)
                    <div class="chart-box"><canvas id="dailyChart" role="img" aria-label="Daily revenue"></canvas></div>
                @else
                    <x-admin.empty icon="chart-line" title="No completed sales in this period" />
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Orders by status</h3></div>
            <div class="card-body">
                @if ($statusCounts->isEmpty())
                    <x-admin.empty icon="shopping-cart-off" title="No orders in this period" />
                @else
                    @php $max = max($statusCounts->max(), 1); @endphp
                    @foreach ($statusLabels as $value => $label)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1"><span>{{ $label }}</span><strong>{{ $statusCounts[$value] ?? 0 }}</strong></div>
                            <div class="progress progress-sm"><div class="progress-bar {{ $value === 'canceled' ? 'bg-red' : ($value === 'completed' ? 'bg-teal' : 'bg-primary') }}" style="width: {{ round(($statusCounts[$value] ?? 0) / $max * 100) }}%"></div></div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><div><h3 class="card-title">Last 12 months</h3><div class="card-subtitle">Revenue (bars) and new customers (line)</div></div></div>
            <div class="card-body">
                @if (array_sum($monthly['revenue']) > 0 || array_sum($monthly['customers']) > 0)
                    <div class="chart-box"><canvas id="monthlyChart" role="img" aria-label="Monthly revenue and new customers"></canvas></div>
                @else
                    <x-admin.empty icon="chart-bar" title="No data in the last 12 months" />
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Returns &amp; loyalty</h3></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    @foreach (['pending' => 'Pending returns', 'accepted' => 'Accepted returns', 'rejected' => 'Rejected returns'] as $value => $label)
                        <dt class="col-7 fw-normal text-secondary">{{ $label }}</dt>
                        <dd class="col-5 text-end">{{ $returns[$value]->total ?? 0 }} · {{ $money($returns[$value]->amount ?? 0) }}</dd>
                    @endforeach
                    <dt class="col-7 fw-normal text-secondary mt-2">Points earned</dt><dd class="col-5 text-end mt-2">{{ number_format($loyalty['earned']) }}</dd>
                    <dt class="col-7 fw-normal text-secondary">Points redeemed</dt><dd class="col-5 text-end">{{ number_format($loyalty['redeemed']) }}</dd>
                    <dt class="col-7 fw-normal text-secondary">Points reversed by refunds</dt><dd class="col-5 text-end mb-0">{{ number_format($loyalty['reversed']) }}</dd>
                </dl>
                <div class="form-hint mt-3">Refund amounts are what customers requested; the system does not record payouts.</div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Best-selling products</h3></div>
            @if ($topProducts->isEmpty())
                <x-admin.empty icon="trophy-off" title="No sales in this period" />
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Sales</th></tr></thead>
                        <tbody>
                        @foreach ($topProducts as $row)
                            <tr>
                                <td class="text-truncate" style="max-width: 16rem">{{ $row->product->name_en ?? 'Deleted product' }}</td>
                                <td class="text-end">{{ number_format($row->units) }}</td>
                                <td class="text-end">{{ $money($row->sales) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><div><h3 class="card-title">Slow-moving stock</h3><div class="card-subtitle">In stock, no sales in this period</div></div></div>
            @if ($slowMoving->isEmpty())
                <x-admin.empty icon="circle-check" title="Every stocked size sold in this period" />
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Product</th><th>Size</th><th class="text-end">In stock</th></tr></thead>
                        <tbody>
                        @foreach ($slowMoving as $size)
                            <tr>
                                <td class="text-truncate" style="max-width: 16rem">{{ $size->product->name_en ?? '—' }}</td>
                                <td>{{ $size->size }}</td>
                                <td class="text-end">{{ number_format($size->stock) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><div><h3 class="card-title">Coupon usage</h3><div class="card-subtitle">Non-canceled orders in this period. Discount amounts per order are not stored, so only order value is shown.</div></div></div>
            @if ($coupons->every(fn ($c) => $c->orders_count === 0))
                <x-admin.empty icon="ticket-off" title="No coupon orders in this period" />
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Code</th><th>Discount</th><th class="text-end">Orders</th><th class="text-end">Order value</th><th class="text-end">Total uses</th></tr></thead>
                        <tbody>
                        @foreach ($coupons->where('orders_count', '>', 0) as $coupon)
                            <tr>
                                <td><code>{{ $coupon->code }}</code></td>
                                <td>{{ rtrim(rtrim($coupon->discount_value, '0'), '.') }}%</td>
                                <td class="text-end">{{ $coupon->orders_count }}</td>
                                <td class="text-end">{{ $money($coupon->orders_value) }}</td>
                                <td class="text-end">{{ $coupon->used_count }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
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
            const css = getComputedStyle(document.documentElement);
            const primary = css.getPropertyValue('--tblr-primary').trim() || '#1d4ed8';
            const teal = css.getPropertyValue('--tblr-teal').trim() || '#0d9488';
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
            Chart.defaults.color = '#64748b';
            const grid = { color: '#eef2f7' };

            const daily = document.getElementById('dailyChart');
            if (daily) {
                const d = @json($daily);
                new Chart(daily, {
                    type: 'line',
                    data: { labels: d.labels, datasets: [{ label: 'Revenue', data: d.revenue, borderColor: teal, backgroundColor: teal + '22', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, grid } } },
                });
            }

            const monthly = document.getElementById('monthlyChart');
            if (monthly) {
                const m = @json($monthly);
                new Chart(monthly, {
                    data: {
                        labels: m.labels,
                        datasets: [
                            { type: 'bar', label: 'Revenue', data: m.revenue, backgroundColor: primary, borderRadius: 4, maxBarThickness: 28, yAxisID: 'y' },
                            { type: 'line', label: 'New customers', data: m.customers, borderColor: teal, backgroundColor: teal, tension: .3, yAxisID: 'y1' },
                        ],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid }, y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0 } } },
                    },
                });
            }
        })();
    </script>
@endpush
