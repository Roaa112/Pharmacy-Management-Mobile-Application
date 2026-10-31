<?php

return [
    // Shown in the admin sidebar and page titles.
    'name' => env('PHARMACY_NAME', 'Alkawthar'),

    // Currency label used in the admin dashboard. Prices themselves carry no currency.
    'currency' => env('PHARMACY_CURRENCY', 'EGP'),

    // A product size at or below this stock (but above zero) is reported as "low stock".
    'low_stock_threshold' => (int) env('PHARMACY_LOW_STOCK_THRESHOLD', 5),

    // Timezone the admin uses when entering flash sale, discount and coupon dates.
    // Those dates are stored as local wall-clock times; see App\Support\StoreClock.
    // Asia/Baghdad (UTC+3) matches the "+3 hours" checkout previously hard-coded.
    'timezone' => env('PHARMACY_TIMEZONE', 'Asia/Baghdad'),
];
