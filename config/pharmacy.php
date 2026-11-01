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
];
