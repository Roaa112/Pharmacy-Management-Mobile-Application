@extends('admin.layout')

@php $payment = \App\Models\PaymentMethod::pluck('name_en', 'code')->all(); @endphp

@section('title', 'Orders')
@section('pretitle', 'Sales')

@section('actions')
    <span class="badge bg-teal-lt fs-5 py-2 px-3">Completed revenue: <x-admin.money :amount="$totalEarned" class="fw-bold" /></span>
@endsection

@section('content')
<div class="card">
    <div class="card-header flex-column align-items-stretch gap-3">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <a href="{{ route('dashboard.orders.index', array_filter(['search' => $search])) }}" class="nav-link {{ !$status ? 'active' : '' }}">
                    All <span class="badge bg-secondary-lt ms-1">{{ $statusCounts->sum() }}</span>
                </a>
            </li>
            @foreach ($statusLabels as $value => $label)
                <li class="nav-item">
                    <a href="{{ route('dashboard.orders.index', array_filter(['status' => $value, 'search' => $search])) }}" class="nav-link {{ $status === $value ? 'active' : '' }}">
                        {{ $label }} <span class="badge {{ $value === 'pending' && ($statusCounts[$value] ?? 0) ? 'bg-orange-lt' : 'bg-secondary-lt' }} ms-1">{{ $statusCounts[$value] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="d-flex gap-2">
            @if ($status) <input type="hidden" name="status" value="{{ $status }}"> @endif
            <div class="input-icon flex-fill" style="max-width: 24rem">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Order number, customer name or phone">
            </div>
            <button class="btn btn-primary">Search</button>
        </form>
    </div>

    @if ($orders->isEmpty())
        <x-admin.empty icon="shopping-cart-off" title="No orders found" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Order</th><th>Customer</th><th class="d-none d-md-table-cell">Items</th><th class="d-none d-md-table-cell">Payment</th><th>Status</th><th class="text-end">Total</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td>
                            <a href="{{ route('dashboard.orders.show', $order) }}" class="fw-medium">#{{ $order->id }}</a>
                            <div class="small text-secondary">{{ $order->created_at->format('M j, Y · H:i') }}</div>
                        </td>
                        <td>
                            @if ($order->user)
                                <a href="{{ route('dashboard.customers.show', $order->user) }}" class="text-reset">{{ $order->user->name }}</a>
                                <div class="small text-secondary">{{ $order->user->phone }}</div>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td class="d-none d-md-table-cell">{{ $order->items_count }}</td>
                        <td class="d-none d-md-table-cell">
                            {{ $payment[$order->payment_method] ?? $order->payment_method }}
                            @if ($order->payment_status === 'awaiting_verification' && !$order->payment_image)
                                <div class="small text-danger">No receipt</div>
                            @endif
                        </td>
                        <td>
                            <x-admin.status :status="$order->status" />
                            @if ($order->returnRequests->contains('status', 'accepted'))
                                <span class="badge bg-purple-lt ms-1">Refunded</span>
                            @elseif ($order->returnRequests->contains('status', 'pending'))
                                <span class="badge bg-yellow-lt ms-1">Return requested</span>
                            @endif
                        </td>
                        <td class="text-end"><x-admin.money :amount="$order->total_price" /></td>
                        <td><a href="{{ route('dashboard.orders.show', $order) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$orders" />
    @endif
</div>
@endsection
