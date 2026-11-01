<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\Coupon;
use App\Modules\Coupon\Services\CouponEvaluator;
use App\Modules\Promotions\Engine\EligibilityChecker;
use App\Modules\Promotions\Engine\PricedLine;
use App\Modules\Promotions\Engine\PricingInput;
use App\Modules\Promotions\Engine\TargetMatcher;
use App\Support\Money;

/**
 * A customer-entered coupon. Eligible items: the coupon's targets (all items when it has
 * none), minus excluded ones, and — unless combines_with_promotions — minus items whose price
 * an item promotion already reduced. The minimum order is checked on the eligible items.
 *
 * The discount itself is CouponEvaluator::discountMinor (percent or fixed, capped, never more
 * than its base), so preview and checkout share one formula.
 */
class CouponStrategy
{
    public function __construct(private EligibilityChecker $eligibility, private CouponEvaluator $calculator)
    {
    }

    /**
     * @param  list<PricedLine>  $lines
     * @return array{coupon: Coupon, eligible: int, items_discount: int, lines: list<string>}|array{coupon: ?Coupon, reason: string, params: array}|null
     */
    public function evaluate(PricingInput $input, array $lines): ?array
    {
        if ($input->couponCode === null || trim($input->couponCode) === '') {
            return null;
        }

        $coupon = Coupon::with(['targets'])->where('code', trim($input->couponCode))
            ->when($input->forCheckout, fn ($q) => $q->lockForUpdate())
            ->first();

        if (!$coupon) {
            return ['coupon' => null, 'reason' => CouponEvaluator::INVALID, 'params' => []];
        }

        if ($reason = $this->eligibility->coupon($coupon, $input)) {
            return ['coupon' => $coupon, 'reason' => $reason[0], 'params' => $reason[1]];
        }

        $eligible = array_values(array_filter($lines, fn (PricedLine $line) => TargetMatcher::matches($coupon->targets, $line->product, $line->size, true)
            && ($coupon->combines_with_promotions || !$line->isDiscounted())));

        if (!$eligible) {
            return ['coupon' => $coupon, 'reason' => 'no_eligible_items', 'params' => []];
        }

        $eligibleMinor = array_sum(array_map(fn (PricedLine $line) => $line->total(), $eligible));

        if ($eligibleMinor < Money::toMinor($coupon->min_order_amount)) {
            return ['coupon' => $coupon, 'reason' => CouponEvaluator::BELOW_MINIMUM, 'params' => ['amount' => (float) $coupon->min_order_amount]];
        }

        return [
            'coupon' => $coupon,
            'eligible' => $eligibleMinor,
            'items_discount' => $this->calculator->discountMinor($coupon, $eligibleMinor, 0),
            'lines' => array_map(fn (PricedLine $line) => (string) $line->key, $eligible),
        ];
    }

    /** The final discount once the delivery fee left after delivery promotions is known. */
    public function discount(Coupon $coupon, int $eligibleMinor, int $deliveryMinor): int
    {
        return $this->calculator->discountMinor($coupon, $eligibleMinor, $deliveryMinor);
    }
}
