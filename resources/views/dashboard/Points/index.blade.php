@extends('admin.layout')

@section('title', 'Loyalty points')
@section('pretitle', 'Sales')
@section('subtitle', 'Valid, unredeemed points per order. Redeem them when the customer uses their reward.')

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-4">
        <x-admin.stat label="Valid points" icon="star" tone="yellow" :value="number_format($valid_points_total ?? 0)" :hint="count($orders).' orders'" />
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ route('dashboard.points.index') }}" class="row g-2 w-100">
            <div class="col-md-5"><input type="text" name="user_name" class="form-control" placeholder="Customer name" value="{{ request('user_name') }}"></div>
            <div class="col-md-3"><input type="text" name="order_id" class="form-control" placeholder="Order number" value="{{ request('order_id') }}" inputmode="numeric"></div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="ti ti-search me-1"></i>Search</button>
                @if (request()->hasAny(['user_name', 'order_id']))
                    <a href="{{ route('dashboard.points.index') }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    @if ($orders->isEmpty())
        <x-admin.empty icon="star-off" title="No valid points" text="Points appear here after customers place orders, until they expire or are redeemed." />
    @else
        {{-- Bulk form. Row buttons use their own forms below (forms cannot be nested). --}}
        <form action="{{ route('dashboard.points.redeemMultiple') }}" method="POST" id="redeem-multiple-form"
              data-confirm="Redeem the points of all selected orders?">
            @csrf @method('PATCH')
        </form>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                <tr>
                    <th class="w-1"><input type="checkbox" class="form-check-input" id="select-all" aria-label="Select all"></th>
                    <th>Customer</th><th>Order</th><th class="text-end">Points</th><th>Earned</th><th>Expires</th><th class="w-1"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td><input type="checkbox" name="order_ids[]" value="{{ $order['order_id'] }}" form="redeem-multiple-form" class="form-check-input order-checkbox" aria-label="Select order {{ $order['order_id'] }}"></td>
                        <td>{{ $order['user_name'] ?? '—' }}</td>
                        <td><a href="{{ route('dashboard.orders.show', $order['order_id']) }}">#{{ $order['order_id'] }}</a></td>
                        <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($order['points_earned']) }}</span>
                            @if ($order['points_reversed'])<div class="small text-secondary">{{ $order['points_reversed'] }} reversed by refund</div>@endif</td>
                        <td class="small">{{ \Carbon\Carbon::parse($order['created_at'])->format('M j, Y') }}</td>
                        <td class="small">{{ \Carbon\Carbon::parse($order['expires_at'])->format('M j, Y') }}</td>
                        <td>
                            <form action="{{ route('dashboard.points.redeem', $order['order_id']) }}" method="POST" data-confirm="Redeem the points of order #{{ $order['order_id'] }}?">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-success text-nowrap"><i class="ti ti-check me-1"></i>Redeem</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex align-items-center">
            <span class="text-secondary small" id="selected-count">0 selected</span>
            <button type="submit" form="redeem-multiple-form" class="btn btn-success ms-auto" id="bulk-redeem-btn" disabled>
                <i class="ti ti-checks me-1"></i>Redeem selected
            </button>
        </div>
    @endif
</div>
@endsection

@push('scripts')
    <script>
        (function () {
            const all = document.getElementById('select-all');
            if (!all) return;
            const boxes = () => document.querySelectorAll('.order-checkbox');
            const refresh = () => {
                const n = document.querySelectorAll('.order-checkbox:checked').length;
                document.getElementById('selected-count').textContent = n + ' selected';
                document.getElementById('bulk-redeem-btn').disabled = n === 0;
            };
            all.addEventListener('change', () => { boxes().forEach(cb => cb.checked = all.checked); refresh(); });
            boxes().forEach(cb => cb.addEventListener('change', refresh));
        })();
    </script>
@endpush
