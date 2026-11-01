<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Support\Money;

/** A percentage off the unit price (clamped to 0–100 %, rounded half up to 0.01). */
class PercentageDiscountStrategy extends ItemDiscountStrategy
{
    public function apply(DiscountRule $promotion, int $unit): int
    {
        return Money::applyPercent($unit, $promotion->discount_value);
    }
}
