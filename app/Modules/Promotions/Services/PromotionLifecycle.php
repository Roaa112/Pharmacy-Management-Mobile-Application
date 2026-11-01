<?php

namespace App\Modules\Promotions\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\PromotionRedemption;
use App\Models\Setting;
use App\Modules\Promotions\Engine\PricingResult;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * When promotion and coupon usage counts, written only here:
 *
 *  - reserved  at checkout, in the checkout transaction, under the row locks the engine took
 *              (coupon row, limited promotions): limits are enforced the moment the order exists,
 *              so two concurrent checkouts cannot both take the last use;
 *  - consumed  when the order completes;
 *  - released  when the order is canceled: the use goes back (coupons: when
 *              settings.coupon_release_on_cancel, the default — decision D5).
 *
 * Refunds and returns after delivery do not give a use back: the order happened.
 * coupons.used_count moves with the redemptions (+1 reserved, −1 released). It is the global
 * limit's counter, so uses recorded before redemptions existed still count.
 */
class PromotionLifecycle
{
    public function reserve(Order $order, PricingResult $result): void
    {
        $now = now();

        foreach ($result->applied as $applied) {
            if ($applied['source'] !== 'promotion') {
                continue;
            }

            PromotionRedemption::firstOrCreate(
                ['discount_rule_id' => $applied['id'], 'order_id' => $order->id],
                [
                    'user_id' => $order->user_id,
                    'status' => PromotionRedemption::RESERVED,
                    'discount_amount' => Money::fromMinor(($applied['discount'] ?? 0) + ($applied['delivery_discount'] ?? 0)),
                    'reward_quantity' => $applied['reward_quantity'] ?? 0,
                ],
            );
        }

        if ($result->coupon) {
            CouponRedemption::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'coupon_id' => $result->coupon->id,
                    'user_id' => $order->user_id,
                    'code' => $result->coupon->code,
                    'status' => CouponRedemption::RESERVED,
                    'discount_amount' => Money::fromMinor($result->couponDiscount),
                    'reserved_at' => $now,
                ],
            );
            // Older readers of coupon_user (per-customer history) and used_count keep working.
            $result->coupon->users()->attach($order->user_id);
            $result->coupon->increment('used_count');
        }
    }

    public function consume(Order $order): void
    {
        PromotionRedemption::where('order_id', $order->id)->where('status', PromotionRedemption::RESERVED)
            ->update(['status' => PromotionRedemption::CONSUMED, 'consumed_at' => now()]);

        CouponRedemption::where('order_id', $order->id)->where('status', CouponRedemption::RESERVED)
            ->update(['status' => CouponRedemption::CONSUMED, 'consumed_at' => now()]);
    }

    public function release(Order $order): void
    {
        PromotionRedemption::where('order_id', $order->id)->whereIn('status', PromotionRedemption::COUNTING)
            ->update(['status' => PromotionRedemption::RELEASED, 'released_at' => now()]);

        if (!(Setting::current()->coupon_release_on_cancel ?? true)) {
            return;
        }

        $redemption = CouponRedemption::where('order_id', $order->id)->whereIn('status', CouponRedemption::COUNTING)->lockForUpdate()->first();
        if ($redemption) {
            $redemption->update(['status' => CouponRedemption::RELEASED, 'released_at' => now()]);
            $coupon = Coupon::withTrashed()->lockForUpdate()->find($redemption->coupon_id);
            if ($coupon) {
                $pivotId = DB::table('coupon_user')->where('coupon_id', $coupon->id)->where('user_id', $order->user_id)->max('id');
                if ($pivotId) {
                    DB::table('coupon_user')->where('id', $pivotId)->delete();
                }
                if ($coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }
            }
        }
    }
}
