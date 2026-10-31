@extends('admin.layout')

@php
    $payment = ['cash' => 'Cash', 'zaincash' => 'Zain Cash', 'qicard' => 'Qi Card'];
    $itemsTotal = $order->items->sum('total_price');
    $flow = ['pending', 'confirmed', 'on_delivery', 'completed'];
    $reached = $order->status === 'canceled' ? -1 : array_search($order->status, $flow, true);
@endphp

@section('title', 'Order #'.$order->id)
@section('pretitle')
    <a href="{{ route('dashboard.orders.index') }}" class="text-reset">Orders</a>
@endsection
@section('subtitle', 'Placed '.$order->created_at->format('M j, Y \a\t H:i'))

@section('actions')
    <x-admin.status :status="$order->status" class="fs-5 py-2 px-3" />
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-body">
                @if ($order->status === 'canceled')
                    <div class="text-danger fw-medium"><i class="ti ti-circle-x me-1"></i>This order was canceled. Its items were returned to stock.</div>
                @else
                    <ul class="steps steps-counter steps-teal my-2">
                        @foreach ($flow as $i => $step)
                            <li class="step-item {{ $i === $reached ? 'active' : '' }}">{{ $statusLabels[$step] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Items</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th>Product</th><th>Size</th><th class="text-end">Unit price</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-admin.thumb :src="$item->product?->image ? asset($item->product->image) : null" />
                                    <div class="min-w-0">
                                        <div class="fw-medium text-truncate">{{ $item->product_name_en ?? $item->product->name_en ?? 'Unknown product' }}</div>
                                        @if ($item->product?->trashed())
                                            <span class="badge bg-secondary-lt">Deleted product</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $item->size_label ?? $item->size->size ?? '—' }}</td>
                            <td class="text-end">
                                <x-admin.money :amount="$item->price_at_time" />
                                @if ($item->original_price && (float) $item->original_price > (float) $item->price_at_time)
                                    <div class="small text-secondary text-decoration-line-through">{{ number_format((float) $item->original_price, 2) }}</div>
                                @endif
                            </td>
                            <td class="text-end">{{ $item->quantity }}</td>
                            <td class="text-end"><x-admin.money :amount="$item->total_price" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <dl class="row mb-0 ms-auto" style="max-width: 22rem">
                    <dt class="col-6 fw-normal text-secondary">Items</dt><dd class="col-6 text-end mb-1"><x-admin.money :amount="$itemsTotal" /></dd>
                    <dt class="col-6 fw-normal text-secondary">Delivery</dt><dd class="col-6 text-end mb-1"><x-admin.money :amount="$order->delivery_fee" /></dd>
                    @if ($order->coupon)
                        <dt class="col-6 fw-normal text-secondary">Coupon <code>{{ $order->coupon->code }}</code></dt>
                        <dd class="col-6 text-end mb-1 text-teal">− <x-admin.money :amount="max(0, $itemsTotal + $order->delivery_fee - $order->total_price)" /></dd>
                    @endif
                    <dt class="col-6 fs-3">Total</dt><dd class="col-6 text-end fs-3 fw-bold mb-0"><x-admin.money :amount="$order->total_price" /></dd>
                </dl>
            </div>
        </div>

        @if ($order->gift_description || $order->notes)
            <div class="card mb-3">
                <div class="card-body">
                    @if ($order->gift_description)
                        <div class="mb-2"><i class="ti ti-gift text-teal me-1"></i><strong>Gift:</strong> {{ $order->gift_description }}</div>
                    @endif
                    @if ($order->notes)
                        <div><i class="ti ti-note text-secondary me-1"></i><strong>Customer notes:</strong> {{ $order->notes }}</div>
                    @endif
                </div>
            </div>
        @endif

        @if ($order->returnRequests->isNotEmpty())
            <div class="card">
                <div class="card-header"><h3 class="card-title">Return requests</h3></div>
                <div class="list-group list-group-flush">
                    @foreach ($order->returnRequests as $return)
                        <a href="{{ route('dashboard.returns.show', $return->id) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                            <span>{{ $return->reason }} · <x-admin.money :amount="$return->refund_amount ?? 0" /></span>
                            <x-admin.status :status="$return->status" />
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Update status</h3></div>
            <div class="card-body">
                @if ($nextStatuses->isEmpty())
                    <p class="text-secondary mb-0">This order is {{ strtolower($statusLabels[$order->status] ?? $order->status) }} and can no longer change.</p>
                @else
                    <form method="POST" action="{{ route('dashboard.orders.updateStatus', $order) }}" id="status-form">
                        @csrf @method('PUT')
                        <select name="status" class="form-select mb-2" aria-label="New status" required>
                            @foreach ($nextStatuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-primary w-100">Update status</button>
                    </form>
                    <div class="form-hint mt-2">Canceling returns every item to stock. Completed and canceled orders are final.</div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Customer</h3></div>
            <div class="card-body">
                @if ($order->user)
                    <a href="{{ route('dashboard.customers.show', $order->user) }}" class="fw-medium">{{ $order->user->name }}</a>
                    <div class="text-secondary small mt-1"><i class="ti ti-phone me-1"></i>{{ $order->user->phone ?? '—' }}</div>
                    <div class="text-secondary small"><i class="ti ti-mail me-1"></i>{{ $order->user->email }}</div>
                @else
                    <span class="text-secondary">Customer account removed</span>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Delivery</h3></div>
            <div class="card-body small">
                @if ($order->address)
                    <div>{{ $order->address->governorate }}, {{ $order->address->city }}</div>
                    <div>{{ $order->address->street }}</div>
                    @if ($order->address->building || $order->address->apartment)
                        <div>Building {{ $order->address->building ?? '—' }}, apt {{ $order->address->apartment ?? '—' }}</div>
                    @endif
                    @if ($order->address->landmark)
                        <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($order->address->landmark) }}" target="_blank" rel="noopener noreferrer">
                            <i class="ti ti-map-pin me-1"></i>{{ $order->address->landmark }}
                        </a>
                    @endif
                @elseif ($order->address_id === null)
                    <i class="ti ti-building-store me-1"></i>Pickup from the pharmacy
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Payment</h3></div>
            <div class="card-body">
                <div class="mb-2">{{ $payment[$order->payment_method] ?? $order->payment_method }}</div>
                @if ($order->payment_image)
                    <a href="{{ asset('storage/'.$order->payment_image) }}" target="_blank" rel="noopener noreferrer">
                        <img src="{{ asset('storage/'.$order->payment_image) }}" alt="Payment receipt" class="img-fluid rounded border" loading="lazy" style="max-height: 14rem">
                    </a>
                @elseif (in_array($order->payment_method, ['zaincash', 'qicard']))
                    <div class="text-danger small">No receipt uploaded</div>
                @endif
                <div class="text-secondary small mt-2">Loyalty points: {{ (int) $order->points_earned }} ({{ $order->points_status }})</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        document.getElementById('status-form')?.addEventListener('submit', function (event) {
            if (this.status.value === 'canceled' && !confirm('Cancel this order? All items go back to stock and this cannot be undone.')) {
                event.preventDefault();
            }
        });
    </script>
@endpush
