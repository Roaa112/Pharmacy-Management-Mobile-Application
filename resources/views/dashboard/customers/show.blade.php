@extends('admin.layout')

@section('title', $customer->name)
@section('pretitle')
    <a href="{{ route('dashboard.customers.index') }}" class="text-reset">Customers</a>
@endsection
@section('subtitle', 'Customer since '.$customer->created_at?->format('M j, Y'))

@section('actions')
    @unless ($customer->isActive())<span class="badge bg-red-lt fs-5 py-2 px-3 me-2">Deactivated</span>@endunless
    <form method="POST" action="{{ route('dashboard.customers.toggleActive', $customer) }}" class="d-inline"
          data-confirm="{{ $customer->isActive() ? 'Deactivate this customer? They are signed out and cannot log in; their orders stay.' : 'Reactivate this customer?' }}">
        @csrf @method('PATCH')
        <button class="btn {{ $customer->isActive() ? 'btn-outline-danger' : 'btn-outline-success' }}">{{ $customer->isActive() ? 'Deactivate' : 'Reactivate' }}</button>
    </form>
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Orders" icon="shopping-cart" :value="number_format($summary['orders'])" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Completed spend" icon="cash" tone="teal" :value="number_format((float) $summary['spent'], 2).' '.config('pharmacy.currency')" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Unredeemed points" icon="star" tone="yellow" :value="number_format((int) $summary['points'])" :hint="$summary['points_owed'] ? number_format($summary['points_owed']).' owed after refunds of redeemed points' : 'Still valid'" /></div>
    <div class="col-sm-6 col-xl-3"><x-admin.stat label="Return requests" icon="receipt-refund" tone="orange" :value="number_format($summary['returns'])" /></div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Contact</h3></div>
            <div class="card-body">
                <div class="mb-2"><i class="ti ti-mail text-secondary me-2"></i><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a></div>
                <div class="mb-2"><i class="ti ti-phone text-secondary me-2"></i>{{ $customer->phone ?? '—' }}</div>
                <div><i class="ti ti-rosette-discount-check text-secondary me-2"></i>{{ $customer->is_verified ? 'Verified account' : 'Not verified' }}</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Addresses</h3></div>
            @if ($addresses->isEmpty())
                <x-admin.empty icon="map-pin-off" title="No saved addresses" />
            @else
                <div class="list-group list-group-flush">
                    @foreach ($addresses as $address)
                        <div class="list-group-item small">
                            <div class="d-flex justify-content-between">
                                <span class="fw-medium text-capitalize">{{ $address->type }}</span>
                                @if ($address->is_default) <span class="badge bg-teal-lt">Default</span> @endif
                            </div>
                            <div class="text-secondary">{{ $address->governorate }}, {{ $address->city }} · {{ $address->street }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Order history</h3></div>
            @if ($orders->isEmpty())
                <x-admin.empty icon="shopping-cart-off" title="No orders yet" />
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><a href="{{ route('dashboard.orders.show', $order) }}" class="fw-medium">#{{ $order->id }}</a></td>
                                <td class="small">{{ $order->created_at->format('M j, Y') }}</td>
                                <td>{{ $order->items_count }}</td>
                                <td><x-admin.status :status="$order->status" /></td>
                                <td class="text-end"><x-admin.money :amount="$order->total_price" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <x-admin.pagination :paginator="$orders" />
            @endif
        </div>
    </div>
</div>
@endsection
