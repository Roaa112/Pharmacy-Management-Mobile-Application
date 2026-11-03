@props(['status'])

@php
    $map = [
        // orders
        'pending' => ['Pending', 'orange'],
        'confirmed' => ['Confirmed', 'blue'],
        'on_delivery' => ['On delivery', 'cyan'],
        'completed' => ['Completed', 'green'],
        'canceled' => ['Canceled', 'red'],
        // returns
        'accepted' => ['Accepted', 'green'],
        'rejected' => ['Rejected', 'red'],
        // points
        'earned' => ['Earned', 'teal'],
        'redeemed' => ['Redeemed', 'secondary'],
        'picking' => ['Picking', 'indigo'],
        'packed' => ['Packed', 'purple'],
        // payments & refunds
        'unpaid' => ['Unpaid', 'secondary'],
        'awaiting_verification' => ['Awaiting verification', 'orange'],
        'paid' => ['Paid', 'green'],
        'partially_paid' => ['Partially paid', 'yellow'],
        'failed' => ['Failed', 'red'],
        'partially_refunded' => ['Partially refunded', 'purple'],
        'refunded' => ['Refunded', 'purple'],
        'processing' => ['Processing', 'blue'],
        'succeeded' => ['Succeeded', 'green'],
        'refund_none' => ['No refund', 'secondary'],
        'refund_partial' => ['Partly refunded', 'purple'],
        'refund_full' => ['Fully refunded', 'purple'],
        // purchasing, transfers, counts
        'draft' => ['Draft', 'secondary'],
        'ordered' => ['Ordered', 'blue'],
        'partially_received' => ['Partially received', 'yellow'],
        'received' => ['Received', 'green'],
        'closed' => ['Closed', 'secondary'],
        'requested' => ['Requested', 'orange'],
        'approved' => ['Approved', 'blue'],
        'in_transit' => ['In transit', 'cyan'],
        'dispatched' => ['Dispatched', 'cyan'],
        'overstock' => ['Overstock', 'purple'],
        // generic
        'active' => ['Active', 'green'],
        'inactive' => ['Inactive', 'secondary'],
        'expired' => ['Expired', 'secondary'],
        'scheduled' => ['Scheduled', 'blue'],
        'used_up' => ['Used up', 'secondary'],
        'archived' => ['Archived', 'secondary'],
        // promotion and coupon usage
        'reserved' => ['Reserved', 'orange'],
        'consumed' => ['Consumed', 'green'],
        'released' => ['Released', 'secondary'],
        'out' => ['Out of stock', 'red'],
        'low' => ['Low stock', 'orange'],
        'ok' => ['In stock', 'green'],
        // loyalty points, redemptions, imports and exports
        'depleted' => ['Used', 'secondary'],
        'void' => ['Void', 'red'],
        'fulfilled' => ['Fulfilled', 'green'],
        'cancelled' => ['Cancelled', 'red'],
        'completed_with_errors' => ['Completed with errors', 'orange'],
    ];
    [$text, $color] = $map[$status] ?? [ucfirst(str_replace('_', ' ', (string) $status)), 'secondary'];
@endphp

<span {{ $attributes->merge(['class' => "badge bg-{$color}-lt"]) }}>{{ $text }}</span>
