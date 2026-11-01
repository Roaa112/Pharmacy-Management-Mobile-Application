<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Models\PromotionReward;
use App\Modules\Promotions\Engine\PricedLine;

/**
 * Buy X get Y: X qualifying units (min_quantity) earn each free-product reward's quantity.
 *
 *  - repeatable: every full X earns the reward again (4 units of "buy 2 get 1" = 2 gifts);
 *    otherwise the reward is earned once;
 *  - max_applications_per_order caps the number of times;
 *  - units already reduced by an item promotion do not count unless the promotion is
 *    stackable (no flash sale + free gift on the same units by accident).
 */
class BuyXGetYStrategy
{
    public function evaluate(DiscountRule $promotion, OrderContext $context): OrderOutcome
    {
        $matching = $context->eligibleLines($promotion, false);
        if (!$matching) {
            return OrderOutcome::irrelevant();
        }

        $qualifying = array_filter($matching, fn (PricedLine $line) => $promotion->is_stackable || !$line->isDiscounted());
        $quantity = array_sum(array_map(fn (PricedLine $line) => $line->quantity, $qualifying));
        $excluded = array_sum(array_map(fn (PricedLine $line) => $line->quantity, $matching)) - $quantity;
        $required = max(1, (int) $promotion->min_quantity);

        $times = $promotion->repeatable ? intdiv($quantity, $required) : ($quantity >= $required ? 1 : 0);
        if ($promotion->max_applications_per_order) {
            $times = min($times, (int) $promotion->max_applications_per_order);
        }

        $details = ['qualifying_quantity' => $quantity, 'required_quantity' => $required, 'times' => $times];

        if ($times === 0) {
            $missing = $required - $quantity;
            $outcome = $excluded > 0 && $quantity + $excluded >= $required
                ? OrderOutcome::rejected('qualifying_items_discounted', [], $details)
                : OrderOutcome::rejected('buy_quantity_not_reached', ['missing' => $missing], $details);
            $outcome->hint = ['promotion_id' => $promotion->id, 'name' => $promotion->name, 'type' => $promotion->discount_type,
                'missing_quantity' => $missing, 'required_quantity' => $required];

            return $outcome;
        }

        $rewards = $promotion->rewards->where('reward_type', PromotionReward::FREE_PRODUCT);
        if ($rewards->isEmpty()) {
            return OrderOutcome::rejected('reward_not_configured', [], $details);
        }

        $outcome = new OrderOutcome();
        $outcome->relevant = true;
        $outcome->eligible = array_sum(array_map(fn (PricedLine $line) => $line->total(), $qualifying));
        $outcome->details = $details;

        foreach ($rewards as $reward) {
            $outcome->gifts[] = ['reward' => $reward, 'quantity' => max(1, (int) $reward->quantity) * $times];
        }

        return $outcome;
    }
}
