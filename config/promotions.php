<?php

/*
 * Promotion engine settings that are code-level invariants, not marketing choices. Marketing
 * choices (priority, stacking, limits, tiers, gift fallback...) are columns on each promotion.
 */
return [
    // Length given to a flash sale created without an end (older integrations only; the
    // dashboard requires an end). It is written onto the sale when saved, never recomputed.
    'flash_sale_default_minutes' => 60,

    // Item price precedence when no explicit priority separates two promotions: flash sales
    // first (decision D7), then the most specific target.
    'specificity' => ['variant' => 4, 'product' => 3, 'brand' => 2, 'category' => 1],

    // Upper bound for one cart's gift lines, so a misconfigured repeatable promotion cannot
    // flood an order.
    'max_gift_units_per_reward' => 100,
];
