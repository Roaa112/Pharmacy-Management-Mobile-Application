<?php

namespace App\Modules\Coupon\Services;

use App\Models\Coupon;
use App\Models\Setting;
use App\Support\Money;

/**
 * The one coupon discount formula. Eligibility (dates, limits, customers, items) is decided by
 * the promotion engine (EligibilityChecker / CouponStrategy); this class only turns an eligible
 * amount into a discount, so preview and checkout charge the same.
 *
 * Financial invariants that stay in code: the discount is never negative and never more than
 * what it applies to; percentages are clamped to 0–100.
 */
class CouponEvaluator
{
    public const OK = null;
    public const INVALID = 'invalid';
    public const NOT_STARTED = 'not_started';
    public const EXPIRED = 'expired';
    public const USED_UP = 'used_up';
    public const ALREADY_USED = 'already_used';
    public const BELOW_MINIMUM = 'below_minimum';

    public function appliesToDelivery(Coupon $coupon): bool
    {
        return $coupon->applies_to_delivery ?? (bool) (Setting::current()->coupon_applies_to_delivery ?? true);
    }

    /** Discount in minor units for these eligible items and this delivery fee. */
    public function discountMinor(Coupon $coupon, int $itemsMinor, int $deliveryMinor): int
    {
        $baseMinor = $itemsMinor + ($this->appliesToDelivery($coupon) ? $deliveryMinor : 0);

        $discountMinor = $coupon->type === Coupon::TYPE_FIXED
            ? Money::toMinor($coupon->discount_value)
            : Money::percentOf($baseMinor, $coupon->discount_value);

        if ($coupon->max_discount_amount !== null) {
            $discountMinor = min($discountMinor, Money::toMinor($coupon->max_discount_amount));
        }

        return max(0, min($discountMinor, $baseMinor));
    }
}
