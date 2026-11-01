<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Support\Money;

/** A fixed amount off each unit; the price never goes below zero. */
class FixedAmountDiscountStrategy extends ItemDiscountStrategy
{
    public function apply(DiscountRule $promotion, int $unit): int
    {
        return max(0, $unit - Money::toMinor($promotion->discount_value));
    }
}
