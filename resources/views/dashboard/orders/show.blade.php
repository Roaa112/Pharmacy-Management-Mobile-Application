@extends('admin.layout')

@php
    $itemsTotal = $order->items->sum('total_price');
    $flow = \App\Enums\OrderStatus::FLOW;
    $address = $order->deliveryAddress();
    $refunded = $order->returnRequests->contains('status', 'accepted');
    $reached = $order->status === 'canceled' ? -1 : array_search($order->status, $flow, true);
@endphp

@section('title', 'Order #'.$order->id)
@section('pretitle')
    <a href="{{ route('dashboard.orders.index') }}" class="text-reset">Orders</a>
@endsection
@section('subtitle', 'Placed '.$order->created_at->format('M j, Y \a\t H:i'))

@section('actions')
    <x-admin.status :status="$order->status" class="fs-5 py-2 px-3" />
    @if ($refunded)<span class="badge bg-red-lt fs-5 py-2 px-3 ms-1">Refunded</span>@endif
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
                                        @if ($item->is_gift)
                                            <span class="badge bg-teal-lt"><i class="ti ti-gift me-1"></i>Gift</span>
                                            @if ($item->promotion_snapshot)<span class="small text-secondary">{{ $item->promotion_snapshot['promotion_name'] ?? '' }} · worth {{ number_format((float) ($item->promotion_snapshot['original_price'] ?? $item->original_price) * $item->quantity, 2) }}</span>@endif
                                        @elseif (!empty($item->promotion_snapshot['promotions']))
                                            <span class="small text-teal">{{ collect($item->promotion_snapshot['promotions'])->pluck('name')->implode(' + ') }}</span>
                                        @endif
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
                    @if ($order->promotions->isNotEmpty())
                        {{-- Orders priced by the promotion engine: each order-level discount on its own line. --}}
                        @if ((float) $order->promotion_discount_amount > 0)<dt class="col-6 fw-normal text-secondary">Promotions</dt><dd class="col-6 text-end mb-1 text-teal">− <x-admin.money :amount="$order->promotion_discount_amount" /></dd>@endif
                        @if ((float) $order->coupon_discount_amount > 0)<dt class="col-6 fw-normal text-secondary">Coupon <code>{{ $order->coupon_code }}</code></dt><dd class="col-6 text-end mb-1 text-teal">− <x-admin.money :amount="$order->coupon_discount_amount" /></dd>@endif
                        @if ((float) $order->delivery_discount_amount > 0)<dt class="col-6 fw-normal text-secondary">Delivery discount</dt><dd class="col-6 text-end mb-1 text-teal">− <x-admin.money :amount="$order->delivery_discount_amount" /></dd>@endif
                    @elseif ($order->coupon || (float) $order->discount_amount > 0)
                        <dt class="col-6 fw-normal text-secondary">Coupon @if ($order->coupon)<code>{{ $order->coupon->code }}</code>@endif</dt>
                        <dd class="col-6 text-end mb-1 text-teal">− <x-admin.money :amount="$order->discount_amount ?? max(0, $itemsTotal + $order->delivery_fee - $order->total_price)" /></dd>
                    @endif
                    <dt class="col-6 fs-3">Total</dt><dd class="col-6 text-end fs-3 fw-bold mb-0"><x-admin.money :amount="$order->total_price" /></dd>
                </dl>
            </div>
        </div>

        @if ($order->promotions->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Promotions applied</h3><div class="card-actions small text-secondary">snapshot at checkout · savings <x-admin.money :amount="$order->total_savings" /></div></div>
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr><th>Offer</th><th>Type</th><th class="text-end">Eligible</th><th class="text-end">Discount</th><th class="text-end">Gift units</th></tr></thead>
                        @foreach ($order->promotions as $applied)
                            <tr>
                                <td>@if ($applied->discount_rule_id)<a href="{{ route('dashboard.discounts.show', $applied->discount_rule_id) }}">{{ $applied->name }}</a>@elseif ($applied->coupon_id)<a href="{{ route('dashboard.coupons.show', $applied->coupon_id) }}"><code>{{ $applied->code }}</code></a>@else{{ $applied->name }}@endif</td>
                                <td class="small">{{ str_replace('_', ' ', $applied->source) }} · {{ $applied->promotion_type }}</td>
                                <td class="text-end"><x-admin.money :amount="$applied->eligible_amount" /></td>
                                <td class="text-end"><x-admin.money :amount="(float) $applied->discount_amount + (float) $applied->delivery_discount_amount" /></td>
                                <td class="text-end">{{ $applied->reward_quantity }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endif

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

        @if ($order->address_id || $order->shipping_address)
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Delivery</h3>
                    @can('delivery')
                        @if ($order->shipments->isEmpty() && $order->status !== 'canceled')
                            <form method="POST" action="{{ route('dashboard.delivery.shipments.create', $order) }}" class="ms-auto">@csrf<button class="btn btn-sm btn-outline-primary">Create shipment</button></form>
                        @endif
                    @endcan
                </div>
                @forelse ($order->shipments as $shipment)
                    <div class="card-body border-bottom">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <x-admin.status :status="$shipment->status->value" />
                            @if ($shipment->needs_attention)<span class="badge bg-orange-lt"><i class="ti ti-alert-triangle me-1"></i>Needs attention</span>@endif
                            <span class="text-secondary small">{{ $shipment->provider?->name ?? 'No provider' }}</span>
                            @if ($shipment->tracking_number)<span class="small">· Tracking
                                @if ($shipment->tracking_url)<a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener noreferrer">{{ $shipment->tracking_number }}</a>@else{{ $shipment->tracking_number }}@endif</span>@endif
                            @if ((float) $shipment->cod_amount > 0)<span class="small ms-auto">COD <x-admin.money :amount="$shipment->cod_amount" /> · {{ $shipment->cod_settled_at ? 'settled' : ($shipment->cod_collected_at ? 'collected' : 'due') }}</span>@endif
                        </div>
                        <ul class="list-unstyled small mb-2">
                            @foreach ($shipment->events as $event)
                                <li><span class="text-secondary">{{ $event->occurred_at->format('M j H:i') }}</span> · {{ $event->status ? str_replace('_', ' ', $event->status) : 'unmapped: '.$event->raw_status }}
                                    <span class="text-secondary">({{ $event->source }})</span>@if ($event->note) — {{ $event->note }}@endif</li>
                            @endforeach
                        </ul>
                        @can('delivery')
                            @unless ($shipment->status->isFinal())
                                <div class="row g-2">
                                    <form method="POST" action="{{ route('dashboard.delivery.shipments.assign', $shipment) }}" class="col-md-7 d-flex gap-1">
                                        @csrf
                                        <select name="delivery_provider_id" class="form-select form-select-sm" aria-label="Provider">
                                            @foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected($shipment->delivery_provider_id == $provider->id)>{{ $provider->name }}</option>@endforeach
                                        </select>
                                        <input name="tracking_number" class="form-control form-control-sm" placeholder="Tracking no." value="{{ $shipment->tracking_number }}" aria-label="Tracking number">
                                        <button class="btn btn-sm btn-outline-primary text-nowrap">Hand over</button>
                                    </form>
                                    <form method="POST" action="{{ route('dashboard.delivery.shipments.events', $shipment) }}" class="col-md-5 d-flex gap-1">
                                        @csrf
                                        <select name="status" class="form-select form-select-sm" aria-label="New shipment status">
                                            @foreach (\App\Enums\ShipmentStatus::cases() as $status)<option value="{{ $status->value }}">{{ str_replace('_', ' ', $status->value) }}</option>@endforeach
                                        </select>
                                        <input name="note" class="form-control form-control-sm" placeholder="Note" aria-label="Note" maxlength="255">
                                        <button class="btn btn-sm btn-primary">Update</button>
                                    </form>
                                </div>
                            @endunless
                            @if ($shipment->cod_collected_at && !$shipment->cod_settled_at)
                                <form method="POST" action="{{ route('dashboard.delivery.shipments.settleCod', $shipment) }}" class="mt-2">@csrf<button class="btn btn-sm btn-outline-success">Cash received from courier</button></form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <div class="card-body text-secondary small">No shipment recorded (orders before shipments existed are updated with the status above).</div>
                @endforelse
            </div>
        @endif

        @if ($order->statusHistory->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Status history</h3></div>
                <div class="list-group list-group-flush small">
                    @foreach ($order->statusHistory as $entry)
                        <div class="list-group-item d-flex">
                            <span>{{ $entry->from_status ? str_replace('_', ' ', $entry->from_status).' → ' : '' }}<strong>{{ str_replace('_', ' ', $entry->to_status) }}</strong>
                                <span class="text-secondary">by {{ $entry->actor_type }}{{ $entry->note ? ' · '.$entry->note : '' }}</span></span>
                            <span class="ms-auto text-secondary">{{ $entry->created_at?->format('M j, H:i') }}</span>
                        </div>
                    @endforeach
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
                @if ($address)
                    {{-- The address as it was at checkout. --}}
                    <div>{{ $address['governorate'] ?? '' }}, {{ $address['city'] ?? '' }}</div>
                    <div>{{ $address['street'] ?? '' }}</div>
                    @if (!empty($address['building']) || !empty($address['apartment']))
                        <div>Building {{ $address['building'] ?? '—' }}, apt {{ $address['apartment'] ?? '—' }}</div>
                    @endif
                    @if (!empty($address['landmark']))
                        <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($address['landmark']) }}" target="_blank" rel="noopener noreferrer">
                            <i class="ti ti-map-pin me-1"></i>{{ $address['landmark'] }}
                        </a>
                    @endif
                    @if (!empty($address['backfilled']))<div class="text-secondary mt-1">Copied from the customer's address when snapshots were introduced.</div>@endif
                @elseif ($order->address_id === null)
                    <i class="ti ti-building-store me-1"></i>Pickup from the pharmacy
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Payment</h3>
                <div class="card-actions"><x-admin.status :status="$order->payment_status ?? 'unpaid'" /></div>
            </div>
            <div class="card-body">
                <div class="mb-2">{{ $order->paymentMethod?->name_en ?? $order->payment_method }}
                    @if ($order->paymentMethod?->is_legacy)<span class="badge bg-secondary-lt ms-1" title="Retired method, kept for history">legacy</span>@endif
                </div>
                @forelse ($order->payments as $payment)
                    <div class="border rounded p-2 mb-2 small">
                        <div class="d-flex justify-content-between align-items-center">
                            @can('payments')<a href="{{ route('dashboard.payments.show', $payment) }}" class="fw-medium">{{ $payment->internal_reference }}</a>@else<span class="fw-medium">{{ $payment->internal_reference }}</span>@endcan
                            <x-admin.status :status="$payment->status->value" />
                        </div>
                        <div class="text-secondary">{{ strtoupper($payment->provider) }} · attempt {{ $payment->attempt }} · <x-admin.money :amount="$payment->amount" /></div>
                        @if ($payment->isCod())
                            <div>Collected <x-admin.money :amount="$payment->collected_amount" /> ({{ $payment->collection_status ?? '—' }}){{ $payment->settled_at ? ' · settled '.$payment->settled_at->format('M j') : ' · not settled' }}</div>
                        @endif
                        @if ($payment->failure_reason)<div class="text-danger">{{ $payment->failure_reason }}</div>@endif
                        @if (!empty($payment->metadata['needs_review']))<div class="text-orange"><i class="ti ti-alert-triangle"></i> {{ $payment->metadata['needs_review'] }}</div>@endif

                        @can('payments')
                            @if ($payment->isCod() && in_array($payment->status->value, ['pending', 'partially_paid'], true))
                                <form method="POST" action="{{ route('dashboard.payments.collect', $payment) }}" class="d-flex gap-1 mt-2">
                                    @csrf
                                    <input type="number" name="amount" step="0.01" min="0.01" class="form-control form-control-sm" value="{{ number_format((float) $payment->amount - (float) $payment->collected_amount, 2, '.', '') }}" aria-label="Amount collected">
                                    <select name="via" class="form-select form-select-sm w-auto" aria-label="Collected by"><option value="counter">Counter</option><option value="courier">Courier</option></select>
                                    <button class="btn btn-sm btn-outline-success">Record cash</button>
                                </form>
                            @endif
                            @if ($payment->status->value === 'awaiting_verification')
                                {{-- Legacy manual transfers only: online payments are verified by the provider. --}}
                                <form method="POST" action="{{ route('dashboard.orders.paymentStatus', $order) }}" class="d-flex gap-1 mt-2">
                                    @csrf @method('PATCH')
                                    <button name="payment_status" value="paid" class="btn btn-sm btn-outline-success">Receipt is valid</button>
                                    <button name="payment_status" value="failed" class="btn btn-sm btn-outline-danger">Reject receipt</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <div class="text-secondary small">No payment record.</div>
                @endforelse

                @if ($order->refunds->isNotEmpty())
                    <div class="fw-medium mt-3 mb-1">Refunds <x-admin.status :status="'refund_'.$order->refund_status" /></div>
                    @foreach ($order->refunds as $refund)
                        <div class="d-flex justify-content-between small">
                            @can('refunds')<a href="{{ route('dashboard.refunds.show', $refund) }}">{{ $refund->reference }}</a>@else<span>{{ $refund->reference }}</span>@endcan
                            <span><x-admin.money :amount="$refund->amount" /> <x-admin.status :status="$refund->status" /></span>
                        </div>
                    @endforeach
                @endif

                @if ($order->payment_image && auth('admin')->user()?->can('payments'))
                    <a href="{{ route('dashboard.files.paymentReceipt', $order) }}" target="_blank" rel="noopener noreferrer" class="d-block mt-2">
                        <img src="{{ route('dashboard.files.paymentReceipt', $order) }}" alt="Payment receipt" class="img-fluid rounded border" loading="lazy" style="max-height: 14rem">
                    </a>
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
