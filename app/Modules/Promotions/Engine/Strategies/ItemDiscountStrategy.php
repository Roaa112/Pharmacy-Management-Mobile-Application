<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Modules\Promotions\Engine\EligibilityChecker;
use App\Modules\Promotions\Engine\PricedLine;
use App\Modules\Promotions\Engine\PricingInput;
use App\Modules\Promotions\Engine\TargetMatcher;
use App\Support\Money;

/**
 * Base for the two item price discounts. A rule applies to a line when its targets cover the
 * item (product, variant, brand or category, minus exclusions), the customer/country/dates/limits
 * allow it, the line has the minimum quantity and the cart is inside the order bounds.
 *
 * In the catalogue (listings, product page) a conditional rule (minimum quantity or order
 * bounds) is not shown: it can only be decided for a cart.
 */
abstract class ItemDiscountStrategy
{
    public function __construct(protected EligibilityChecker $eligibility)
    {
    }

    /** Discounted unit price (minor units, never below zero). */
    abstract public function apply(DiscountRule $promotion, int $unit): int;

    /** @return ItemCandidate|array{0: string, 1: array}|null */
    public function candidate(DiscountRule $promotion, PricedLine $line, PricingInput $input, int $originalSubtotal): ItemCandidate|array|null
    {
        $specificity = TargetMatcher::specificity($promotion->targets, $line->product, $line->size, false);
        if ($specificity === null) {
            return null;
        }

        $conditional = ($promotion->min_quantity ?? 0) > 1 || $promotion->min_order_amount !== null || $promotion->max_order_amount !== null;
        if ($input->catalogOnly && $conditional) {
            return null;
        }

        if ($reason = $this->eligibility->promotion($promotion, $input)) {
            return $reason;
        }
        if (($promotion->min_quantity ?? 0) > 1 && $line->quantity < $promotion->min_quantity) {
            return ['below_minimum_quantity', ['quantity' => $promotion->min_quantity]];
        }
        if (!$input->catalogOnly && ($reason = $this->eligibility->orderBounds($promotion, $originalSubtotal))) {
            return $reason;
        }

        return new ItemCandidate(
            source: 'promotion',
            id: $promotion->id,
            type: $promotion->discount_type,
            name: $promotion->name,
            rank: 0,
            priority: (int) $promotion->priority,
            specificity: $specificity,
            stackable: $promotion->is_stackable && !$promotion->is_exclusive,
            apply: fn (int $unit) => $this->capped($promotion, $unit, $this->apply($promotion, $unit)),
            model: $promotion,
        );
    }

    /** max_discount_amount caps the discount per unit. */
    private function capped(DiscountRule $promotion, int $unit, int $discounted): int
    {
        $discounted = max(0, min($unit, $discounted));

        if ($promotion->max_discount_amount !== null) {
            $discounted = max($discounted, $unit - Money::toMinor($promotion->max_discount_amount));
        }

        return $discounted;
    }
}
