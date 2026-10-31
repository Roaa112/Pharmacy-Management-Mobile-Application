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
        // generic
        'active' => ['Active', 'green'],
        'inactive' => ['Inactive', 'secondary'],
        'expired' => ['Expired', 'secondary'],
        'scheduled' => ['Scheduled', 'blue'],
        'used_up' => ['Used up', 'secondary'],
        'out' => ['Out of stock', 'red'],
        'low' => ['Low stock', 'orange'],
        'ok' => ['In stock', 'green'],
    ];
    [$text, $color] = $map[$status] ?? [ucfirst(str_replace('_', ' ', (string) $status)), 'secondary'];
@endphp

<span {{ $attributes->merge(['class' => "badge bg-{$color}-lt"]) }}>{{ $text }}</span>
