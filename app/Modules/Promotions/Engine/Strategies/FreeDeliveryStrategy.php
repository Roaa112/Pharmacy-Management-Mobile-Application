<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Support\Money;

/**
 * Free delivery when the qualifying amount (OrderContext::qualifyingAmount) reaches the
 * promotion's min_amount. max_discount_amount caps how much of the fee is waived.
 */
class FreeDeliveryStrategy
{
    public function evaluate(DiscountRule $promotion, OrderContext $context): OrderOutcome
    {
        $lines = $context->eligibleLines($promotion, true);
        if (!$lines) {
            return OrderOutcome::irrelevant();
        }

        $amount = $context->qualifyingAmount($promotion, $lines, true);
        $threshold = Money::toMinor($promotion->min_amount ?? 0);

        if ($amount < $threshold) {
            $outcome = OrderOutcome::rejected('minimum_spend_not_reached', ['missing' => Money::fromMinor($threshold - $amount)]);
            $outcome->hint = ['promotion_id' => $promotion->id, 'name' => $promotion->name, 'type' => $promotion->discount_type,
                'required_amount' => Money::fromMinor($threshold), 'missing_amount' => Money::fromMinor($threshold - $amount)];

            return $outcome;
        }

        $outcome = new OrderOutcome();
        $outcome->relevant = true;
        $outcome->eligible = $amount;
        $outcome->details = ['qualifying_amount' => Money::fromMinor($amount), 'threshold' => Money::fromMinor($threshold)];
        $outcome->delivery[] = ['reward' => null, 'cap' => $promotion->max_discount_amount !== null ? Money::toMinor($promotion->max_discount_amount) : null];

        return $outcome;
    }
}
