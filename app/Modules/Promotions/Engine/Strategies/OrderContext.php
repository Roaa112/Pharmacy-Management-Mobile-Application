<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Modules\Promotions\Engine\PricedLine;
use App\Modules\Promotions\Engine\PricingInput;
use App\Modules\Promotions\Engine\TargetMatcher;
use App\Models\DiscountRule;

/**
 * The cart as order-level strategies see it: lines after item promotions, plus the coupon's
 * items discount spread over the lines it applies to (for "coupon reduces qualifying spend").
 */
final class OrderContext
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  array<string, int>  $couponShares  line key => coupon discount on that line (minor)
     */
    public function __construct(
        public array $lines,
        public PricingInput $input,
        public int $originalSubtotal,
        public array $couponShares = [],
    ) {
    }

    /** @return list<PricedLine> */
    public function eligibleLines(DiscountRule $promotion, bool $emptyMeansAll): array
    {
        return array_values(array_filter($this->lines, fn (PricedLine $line) => TargetMatcher::matches($promotion->targets, $line->product, $line->size, $emptyMeansAll)));
    }

    /**
     * The qualifying spend of a Spend X / free delivery promotion, defined precisely:
     *   Σ eligible lines at their price after item promotions (or original price when the
     *   promotion's qualifying_basis is "original")
     *   − the coupon's discount on those lines, when coupon_reduces_qualifying and $withCoupon
     *   + the delivery fee before any delivery discount, when count_delivery_fee.
     * Gift lines are never part of it (they are an output of the engine, not an input).
     *
     * @param  list<PricedLine>  $lines
     */
    public function qualifyingAmount(DiscountRule $promotion, array $lines, bool $withCoupon): int
    {
        $amount = 0;
        foreach ($lines as $line) {
            $amount += $promotion->qualifying_basis === 'original' ? $line->originalTotal() : $line->total();
            if ($withCoupon && $promotion->coupon_reduces_qualifying) {
                $amount -= $this->couponShares[(string) $line->key] ?? 0;
            }
        }

        if ($promotion->count_delivery_fee) {
            $amount += $this->input->deliveryFeeMinor ?? 0;
        }

        return max(0, $amount);
    }
}
