<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\DiscountRule;
use App\Models\PromotionReward;
use App\Modules\Promotions\Engine\PricedLine;
use App\Support\Money;

/**
 * Spend X get Y. Each reward has a threshold (its own threshold_amount, else the promotion's
 * min_amount); rewards with the same threshold form a tier. The qualifying amount is
 * OrderContext::qualifyingAmount().
 *
 *  - tier_mode "highest": only the highest tier reached gives its rewards;
 *    "cumulative": every tier reached gives its rewards (10,500 with tiers 5,000 and 10,000
 *    gives both; falling to 7,000 keeps the 5,000 tier's reward and drops the other).
 *  - repeatable (single tier only): every full threshold earns the tier again, capped by
 *    max_applications_per_order.
 *  - rewards: free product (gift line), fixed or percentage discount (on the eligible items),
 *    free delivery.
 *
 * Circularity: discount rewards are decided on the qualifying amount without the coupon (they
 * compete with a non-combinable coupon, and whichever applies, the other does not); gift and
 * delivery rewards on the amount after the coupon's items discount when coupon_reduces_qualifying.
 */
class SpendXGetYStrategy
{
    public function evaluate(DiscountRule $promotion, OrderContext $context): OrderOutcome
    {
        $lines = $context->eligibleLines($promotion, true);
        if (!$lines) {
            return OrderOutcome::irrelevant();
        }

        $withCoupon = $context->qualifyingAmount($promotion, $lines, true);
        $withoutCoupon = $context->qualifyingAmount($promotion, $lines, false);
        $eligibleItems = array_sum(array_map(fn (PricedLine $line) => $line->total(), $lines));

        $tiers = $promotion->rewards->groupBy(fn (PromotionReward $r) => Money::toMinor($r->threshold_amount ?? $promotion->min_amount ?? 0))->sortKeys();
        if ($tiers->isEmpty()) {
            return OrderOutcome::rejected('reward_not_configured');
        }

        $details = ['qualifying_amount' => Money::fromMinor($withCoupon), 'thresholds' => $tiers->keys()->map(fn ($t) => Money::fromMinor($t))->values()->all()];

        $outcome = new OrderOutcome();
        $outcome->relevant = true;
        $outcome->eligible = $withCoupon;
        $outcome->details = $details;

        $next = $tiers->keys()->first(fn ($threshold) => $threshold > $withCoupon);
        if ($next !== null) {
            $outcome->hint = ['promotion_id' => $promotion->id, 'name' => $promotion->name, 'type' => $promotion->discount_type,
                'required_amount' => Money::fromMinor($next), 'missing_amount' => Money::fromMinor($next - $withCoupon)];
        }

        foreach ($this->reachedTiers($promotion, $tiers, $withCoupon) as $threshold => $rewards) {
            $times = $this->times($promotion, $tiers->count(), $threshold, $withCoupon);
            foreach ($rewards as $reward) {
                if ($reward->reward_type === PromotionReward::FREE_PRODUCT) {
                    $outcome->gifts[] = ['reward' => $reward, 'quantity' => max(1, (int) $reward->quantity) * $times];
                } elseif ($reward->reward_type === PromotionReward::FREE_DELIVERY) {
                    $outcome->delivery[] = ['reward' => $reward, 'cap' => $this->cap($reward, $promotion)];
                }
            }
        }

        foreach ($this->reachedTiers($promotion, $tiers, $withoutCoupon) as $threshold => $rewards) {
            $times = $this->times($promotion, $tiers->count(), $threshold, $withoutCoupon);
            foreach ($rewards as $reward) {
                $amount = match ($reward->reward_type) {
                    PromotionReward::FIXED_DISCOUNT => Money::toMinor($reward->value) * $times,
                    PromotionReward::PERCENT_DISCOUNT => Money::percentOf($eligibleItems, $reward->value),
                    default => null,
                };
                if ($amount !== null) {
                    $cap = $this->cap($reward, $promotion);
                    $outcome->discounts[] = ['reward' => $reward, 'amount' => max(0, min($amount, $cap ?? PHP_INT_MAX, $eligibleItems))];
                }
            }
        }

        if (!$outcome->applies()) {
            $first = $tiers->keys()->first();
            $outcome->rejection = ['minimum_spend_not_reached', ['missing' => Money::fromMinor(max(0, $first - $withCoupon))]];
        }

        return $outcome;
    }

    /** @return iterable<int, \Illuminate\Support\Collection> threshold => rewards */
    private function reachedTiers(DiscountRule $promotion, $tiers, int $amount): iterable
    {
        $reached = $tiers->filter(fn ($rewards, $threshold) => $threshold <= $amount);
        if ($reached->isEmpty()) {
            return [];
        }

        return $promotion->tier_mode === DiscountRule::TIER_CUMULATIVE ? $reached : $reached->slice(-1, 1, true);
    }

    private function times(DiscountRule $promotion, int $tierCount, int $threshold, int $amount): int
    {
        if (!$promotion->repeatable || $tierCount > 1 || $threshold <= 0) {
            return 1;
        }

        $times = intdiv($amount, $threshold);

        return max(1, $promotion->max_applications_per_order ? min($times, (int) $promotion->max_applications_per_order) : $times);
    }

    private function cap(PromotionReward $reward, DiscountRule $promotion): ?int
    {
        $caps = array_filter([$reward->max_discount_amount, $promotion->max_discount_amount], fn ($v) => $v !== null);

        return $caps ? min(array_map(fn ($v) => Money::toMinor($v), $caps)) : null;
    }
}
