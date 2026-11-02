<?php

return [
    'status' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'picking' => 'Picking',
        'packed' => 'Packed',
        'on_delivery' => 'On delivery',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],

    'status_meaning' => [
        'pending' => 'Under review',
        'confirmed' => 'Order confirmed and being prepared',
        'on_delivery' => 'On the way',
        'canceled' => 'Order canceled',
        'completed' => 'Delivered successfully',
        'unknown' => 'Unknown',
    ],

    'not_found' => 'Order not found',
    'created' => 'Order placed successfully',
    'cart_empty' => 'Your cart is empty',
    'city_not_covered' => 'We do not deliver to this city yet',
    'below_minimum' => 'The minimum order for this area is :amount',
    'out_of_stock_lines' => 'Some products are not available in the requested quantities',
    'line_out_of_stock' => 'This product is currently out of stock',
    'line_insufficient' => 'Requested quantity not available, only :available left',
    'unknown_product' => 'Unknown product',
    'payment_method_unavailable' => 'This payment method is not available',
    'receipt_required' => 'A payment receipt image is required for this method',
    'cancel_not_allowed' => 'This order cannot be canceled',
    'canceled' => 'Order canceled',
    'transition_refused' => 'The order cannot move from :from to :to',
    'awaiting_online_payment' => 'The online payment has not been confirmed yet',
    'status_updated' => 'Order status updated',
    'size_unavailable' => 'Not available',
    'gift_description' => 'Gift: :product',
    'coupon_rejected' => 'The coupon cannot be used: :reason',
];
