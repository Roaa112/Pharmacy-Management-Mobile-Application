@extends('admin.layout')

@section('title', 'Return request #'.$refund->id)
@section('pretitle')
    <a href="{{ route('dashboard.returns.index') }}" class="text-reset">Returns</a>
@endsection
@section('subtitle', 'Requested '.$refund->created_at->format('M j, Y \a\t H:i'))

@section('actions')
    <x-admin.status :status="$refund->status" class="fs-5 py-2 px-3" />
@endsection

@section('content')
<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Request</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-secondary fw-normal">Reason</dt><dd class="col-sm-8">{{ $refund->reason }}</dd>
                    <dt class="col-sm-4 text-secondary fw-normal">Customer note</dt><dd class="col-sm-8" style="white-space: pre-line">{{ $refund->note ?: '—' }}</dd>
                    <dt class="col-sm-4 text-secondary fw-normal">Requested refund</dt>
                    <dd class="col-sm-8">{!! $refund->refund_amount !== null ? e(number_format((float) $refund->refund_amount, 2).' '.config('pharmacy.currency')) : '—' !!}</dd>
                    <dt class="col-sm-4 text-secondary fw-normal">Refund method</dt><dd class="col-sm-8">{{ $refund->refund_method ?: '—' }}</dd>
                    <dt class="col-sm-4 text-secondary fw-normal">Pickup address</dt><dd class="col-sm-8 mb-0">{{ $refund->address ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Photos</h3></div>
            <div class="card-body">
                @forelse ($refund->images as $image)
                    <a href="{{ route('dashboard.files.returnImage', $image) }}" target="_blank" rel="noopener noreferrer" class="d-inline-block me-2 mb-2">
                        <img src="{{ route('dashboard.files.returnImage', $image) }}" alt="Return photo" class="rounded border" style="width: 9rem; height: 9rem; object-fit: cover" loading="lazy">
                    </a>
                @empty
                    <span class="text-secondary">No photos attached.</span>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Decision</h3></div>
            <div class="card-body">
                @if ($refund->status === 'pending')
                    @php
                        $order = $refund->order;
                        $remaining = $order ? \App\Support\Money::fromMinor(max(0, \App\Support\Money::toMinor($order->total_price) - \App\Support\Money::toMinor($refundedBefore))) : 0;
                    @endphp
                    <p class="small text-secondary">Accepting approves the refund (pay it outside the system), marks the order as refunded and reverses loyalty points in proportion to the amount. Returned units go to <strong>quarantine</strong>: they are never put back on sale.</p>
                    <form action="{{ route('dashboard.returns.accept', $refund->id) }}" method="POST" class="mb-3" data-confirm="Accept this return request?">
                        @csrf
                        <label class="form-label" for="approved_amount">Refund to approve</label>
                        <div class="input-group mb-1">
                            <input type="number" step="0.01" min="0" max="{{ $remaining }}" name="approved_amount" id="approved_amount" class="form-control"
                                   value="{{ old('approved_amount', $refund->refund_amount ?? '') }}" required>
                            <span class="input-group-text">{{ config('pharmacy.currency') }}</span>
                        </div>
                        <div class="small text-secondary mb-3">Up to {{ number_format($remaining, 2) }} left on this order.</div>

                        @if ($order && $order->items->isNotEmpty())
                            <div class="form-label">Units received back (to quarantine)</div>
                            @foreach ($order->items as $item)
                                @php $returned = $item->returnItems->filter(fn ($r) => $r->returnRequest?->status === 'accepted')->sum('quantity'); @endphp
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="flex-fill min-w-0 small">
                                        <div class="text-truncate">{{ $item->product_name_en ?? $item->product->name_en ?? 'Product' }}</div>
                                        <div class="text-secondary">{{ $item->size_label ?? $item->size->size ?? '' }} · ordered {{ $item->quantity }}@if ($returned), returned {{ $returned }}@endif</div>
                                    </div>
                                    <input type="number" min="0" max="{{ $item->quantity - $returned }}" name="quantities[{{ $item->id }}]" value="0" class="form-control form-control-sm" style="width: 5rem" aria-label="Units received back">
                                </div>
                            @endforeach
                        @endif
                        <button class="btn btn-success w-100"><i class="ti ti-check me-1"></i>Accept</button>
                    </form>
                    <form action="{{ route('dashboard.returns.reject', $refund->id) }}" method="POST" data-confirm="Reject this return request?">
                        @csrf
                        <textarea name="reason" class="form-control mb-2" rows="2" placeholder="Reason for rejecting (optional, saved as the note)"></textarea>
                        <button class="btn btn-outline-danger w-100"><i class="ti ti-x me-1"></i>Reject</button>
                    </form>
                @else
                    <p class="text-secondary">This request has been {{ $refund->status === 'canceled' ? 'canceled by the customer' : $refund->status }}{{ $refund->decided_at ? ' on '.$refund->decided_at->format('M j, Y') : '' }}. It can no longer be changed.</p>
                    @if ($refund->status === 'accepted')
                        <dl class="row mb-0 small">
                            <dt class="col-7 text-secondary fw-normal">Approved refund</dt>
                            <dd class="col-5 text-end"><x-admin.money :amount="$refund->approved_amount ?? $refund->refund_amount" /></dd>
                            <dt class="col-7 text-secondary fw-normal">Units to quarantine</dt>
                            <dd class="col-5 text-end">{{ $refund->items->sum('quantity') }}</dd>
                            <dt class="col-7 text-secondary fw-normal">Points reversed</dt>
                            <dd class="col-5 text-end mb-0">
                                {{ $refund->pointReversal->points ?? 0 }}
                                @if ($refund->pointReversal?->points_status_at_reversal === 'redeemed' && $refund->pointReversal->points > 0)
                                    <div class="text-orange">already redeemed: owed</div>
                                @endif
                            </dd>
                        </dl>
                    @endif
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Order</h3></div>
            <div class="card-body">
                @if ($refund->order)
                    <a href="{{ route('dashboard.orders.show', $refund->order) }}" class="fw-medium">Order #{{ $refund->order_id }}</a>
                    <div class="small text-secondary mt-1">
                        <x-admin.status :status="$refund->order->status" /> · <x-admin.money :amount="$refund->order->total_price" />
                    </div>
                @else
                    <span class="text-secondary">Order #{{ $refund->order_id }}</span>
                @endif
                @if ($refund->user)
                    <div class="mt-3"><a href="{{ route('dashboard.customers.show', $refund->user) }}">{{ $refund->user->name }}</a></div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
