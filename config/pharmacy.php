<?php

return [
    // Shown in the admin sidebar and page titles.
    'name' => env('PHARMACY_NAME', 'Alkawthar'),

    // The store currently operates in Egypt only and uses EGP. These are fixed, not environment
    // settings: there is no other market or currency to switch to.
    'country' => 'EG',
    'currency' => 'EGP',

    // A product size at or below this stock (but above zero) is reported as "low stock".
    'low_stock_threshold' => (int) env('PHARMACY_LOW_STOCK_THRESHOLD', 5),

    // The store clock: Egyptian local time (UTC+2, UTC+3 in summer time). Flash sale, discount
    // and coupon dates are entered and stored as local wall-clock times; see App\Support\StoreClock.
    'timezone' => env('PHARMACY_TIMEZONE', 'Africa/Cairo'),

    // Customer login requires a verified email when true. Off by default so existing app users
    // (who were never asked to verify) are not locked out; turn on once the app shows the OTP step.
    'require_verified_login' => (bool) env('REQUIRE_VERIFIED_LOGIN', false),

    // Separation of duties: a refund requested by an admin (accepted return, cancellation, manual
    // request) must be paid out by a different admin. Refunds the system queued itself (duplicate
    // captures, late payments, provider-side refunds) can be processed by any admin with "refunds".
    // Turn off only for a single-admin store.
    'refund_dual_control' => (bool) env('PHARMACY_REFUND_DUAL_CONTROL', true),

    // Batches: units of a batch expiring within this many days are not sold (0 = sellable until the
    // expiry date), and batches expiring within near_expiry_days are reported.
    'min_shelf_life_days' => (int) env('PHARMACY_MIN_SHELF_LIFE_DAYS', 0),
    'near_expiry_days' => (int) env('PHARMACY_NEAR_EXPIRY_DAYS', 90),

    // Refuse goods received without an expiry date. Off by default: the catalogue also sells products
    // that have none, and nothing marks which products are medicines. Turn on for a medicines-only stock.
    'require_expiry_on_receipt' => (bool) env('PHARMACY_REQUIRE_EXPIRY_ON_RECEIPT', false),
];
