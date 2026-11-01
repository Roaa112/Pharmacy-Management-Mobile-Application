<?php

namespace App\Modules\Promotions\Engine;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Support\Money as BaseMoney;
use Illuminate\Support\Facades\DB;

/**
 * Customer, country, date, campaign and usage-limit eligibility shared by every strategy.
 * Returns null when eligible, otherwise [reason code, message parameters].
 *
 * Usage counts and customer history are memoised per store-clock second for catalogue pricing;
 * checkout always counts live, under the row locks taken by the engine.
 */
class EligibilityChecker
{
    private static array $memo = [];

    public function promotion(DiscountRule $promotion, PricingInput $input): ?array
    {
        $simulated = $input->simulate && $input->simulate->id === $promotion->id;

        if (!$simulated) {
            if (!$promotion->is_active || $promotion->trashed()) {
                return ['not_active', []];
            }
            if (!$promotion->starts_at || $promotion->startsAt()->gt($input->now)) {
                return ['not_started', []];
            }
            if (!$promotion->ends_at || $promotion->endsAt()->lt($input->now)) {
                return ['expired', []];
            }
            if ($promotion->campaign && !$promotion->campaign->isLive($input->now)) {
                return ['campaign_inactive', []];
            }
        }

        if ($promotion->country_id && $promotion->country_id !== $input->country?->id) {
            return ['country_not_eligible', []];
        }
        if ($promotion->campaign?->country_id && $promotion->campaign->country_id !== $input->country?->id) {
            return ['country_not_eligible', []];
        }

        foreach ([$promotion->customer_scope, $promotion->campaign?->customer_scope] as $scope) {
            if ($reason = $this->customerScope($scope, $promotion, $input->user, $input)) {
                return $reason;
            }
        }

        if ($promotion->usage_limit && $this->promotionUses($promotion->id, null, $input) >= $promotion->usage_limit) {
            return ['usage_limit_reached', []];
        }
        if ($promotion->per_customer_limit) {
            if (!$input->user) {
                return ['login_required', []];
            }
            if ($this->promotionUses($promotion->id, $input->user->id, $input) >= $promotion->per_customer_limit) {
                return ['customer_limit_reached', []];
            }
        }

        return null;
    }

    /** Cart-amount conditions (on the original prices of the customer's lines). */
    public function orderBounds(DiscountRule $promotion, int $originalSubtotal): ?array
    {
        if ($promotion->min_order_amount !== null && $originalSubtotal < BaseMoney::toMinor($promotion->min_order_amount)) {
            return ['below_minimum_order', ['amount' => (float) $promotion->min_order_amount]];
        }
        if ($promotion->max_order_amount !== null && $originalSubtotal > BaseMoney::toMinor($promotion->max_order_amount)) {
            return ['above_maximum_order', ['amount' => (float) $promotion->max_order_amount]];
        }

        return null;
    }

    public function flashSale(FlashSale $sale, PricingInput $input): ?array
    {
        if ($sale->country_id && $sale->country_id !== $input->country?->id) {
            return ['country_not_eligible', []];
        }

        return null;
    }

    /**
     * Units already sold at this flash sale's price (orders not canceled), optionally for one
     * customer, product or variant.
     */
    public function flashSold(FlashSale $sale, PricingInput $input, ?int $userId = null, ?int $productId = null, ?int $sizeId = null): int
    {
        return $this->memo("flash:{$sale->id}:{$userId}:{$productId}:{$sizeId}", $input, fn () => (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.flash_sale_id', $sale->id)
            ->where('order_items.is_gift', false)
            ->where('orders.status', '!=', 'canceled')
            ->when($userId, fn ($q) => $q->where('orders.user_id', $userId))
            ->when($productId, fn ($q) => $q->where('order_items.product_id', $productId))
            ->when($sizeId, fn ($q) => $q->where('order_items.product_size_id', $sizeId))
            ->sum('order_items.quantity'));
    }

    /** Why this customer cannot use the coupon, or null. Amount checks happen in CouponStrategy. */
    public function coupon(Coupon $coupon, PricingInput $input): ?array
    {
        $now = $input->now;

        return match (true) {
            !$coupon->is_active || $coupon->trashed() => ['invalid', []],
            $coupon->start_at && $now->lt($coupon->start_at) => ['not_started', []],
            $coupon->end_at && $now->gt($coupon->end_at) => ['expired', []],
            $coupon->country_id && $coupon->country_id !== $input->country?->id => ['country_not_eligible', []],
            $coupon->country_id && optional(\App\Models\Country::find($coupon->country_id))->currency_code !== $input->currency() => ['currency_mismatch', []],
            $coupon->usage_limit && (int) $coupon->used_count >= $coupon->usage_limit => ['used_up', []],
            !$input->user => ['login_required', []],
            $coupon->customer_scope === DiscountRule::SCOPE_SPECIFIC && !$coupon->customers()->whereKey($input->user->id)->exists() => ['customer_not_eligible', []],
            $this->couponUserLimitReached($coupon, $input->user) => ['already_used', []],
            $coupon->first_order_only && $this->hasOrders($input->user, $input) => ['first_order_only', []],
            default => null,
        };
    }

    public static function flush(): void
    {
        static::$memo = [];
    }

    private function customerScope(?string $scope, DiscountRule $promotion, ?User $user, PricingInput $input): ?array
    {
        if (!$scope || $scope === DiscountRule::SCOPE_ALL) {
            return null;
        }
        if (!$user) {
            return ['login_required', []];
        }

        $eligible = match ($scope) {
            DiscountRule::SCOPE_NEW => !$this->hasOrders($user, $input),
            DiscountRule::SCOPE_RETURNING => $this->hasOrders($user, $input),
            DiscountRule::SCOPE_SPECIFIC => $promotion->customers->contains('id', $user->id),
            default => false,
        };

        return $eligible ? null : ['customer_not_eligible', []];
    }

    private function hasOrders(User $user, PricingInput $input): bool
    {
        return $this->memo("orders:{$user->id}", $input, fn () => Order::where('user_id', $user->id)->where('status', '!=', 'canceled')->exists());
    }

    private function promotionUses(int $promotionId, ?int $userId, PricingInput $input): int
    {
        return $this->memo("promo:{$promotionId}:{$userId}", $input, fn () => PromotionRedemption::where('discount_rule_id', $promotionId)
            ->whereIn('status', PromotionRedemption::COUNTING)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->count());
    }

    /**
     * A customer's uses of a coupon, always live (the coupon row is locked at checkout): the
     * counting redemptions, or the older coupon_user history when it has more rows.
     */
    private function couponUserLimitReached(Coupon $coupon, User $user): bool
    {
        $limit = $coupon->perUserLimit();
        if ($limit === null) {
            return false;
        }

        $uses = max(
            CouponRedemption::where('coupon_id', $coupon->id)->where('user_id', $user->id)->whereIn('status', CouponRedemption::COUNTING)->count(),
            DB::table('coupon_user')->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count(),
        );

        return $uses >= $limit;
    }

    private function memo(string $key, PricingInput $input, callable $load): mixed
    {
        if ($input->forCheckout || $input->simulate) {
            return $load();
        }

        $second = $input->now->toDateTimeString();
        if ((static::$memo['at'] ?? null) !== $second) {
            static::$memo = ['at' => $second];
        }

        return static::$memo[$key] ??= $load();
    }
}
