<?php

namespace Database\Seeders\Demo;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DiscountRule;
use App\Models\Order;
use App\Models\OrderPromotion;
use App\Models\PromotionCampaign;
use App\Models\FlashSale;
use App\Models\FlashSaleRedemption;
use App\Models\OrderItem;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Support\StoreClock;
use Illuminate\Database\Seeder;

/**
 * The marketing data that needs customers and orders: a campaign with a free-delivery offer,
 * a promotion and a coupon for chosen customers, coupon targets, and the usage history
 * (redemptions and order promotion snapshots) of the demo orders that used a coupon or a gift.
 */
class MarketingSeeder extends Seeder
{
    public function run(): void
    {
        $now = StoreClock::now();
        $sara = User::where('email', 'sara@example.com')->firstOrFail();

        $campaign = PromotionCampaign::create([
            'name_en' => 'Winter wellness', 'name_ar' => 'صحة الشتاء', 'description' => 'Seasonal offers on vitamins and delivery.',
            'starts_at' => $now->copy()->subDays(7), 'ends_at' => $now->copy()->addDays(30), 'is_active' => true,
        ]);

        DiscountRule::create([
            'name_en' => 'Free delivery over 1,500', 'name_ar' => 'توصيل مجاني فوق 1500', 'campaign_id' => $campaign->id,
            'discount_type' => DiscountRule::TYPE_FREE_DELIVERY, 'min_amount' => 1500,
            'starts_at' => $now->copy()->subDays(7), 'ends_at' => $now->copy()->addDays(30),
        ]);

        $vip = DiscountRule::create([
            'name_en' => 'VIP: 5% off vitamins', 'name_ar' => 'لكبار العملاء: خصم 5% على الفيتامينات', 'campaign_id' => $campaign->id,
            'discount_type' => DiscountRule::TYPE_PERCENT, 'discount_value' => 5, 'priority' => 0,
            'customer_scope' => DiscountRule::SCOPE_SPECIFIC, 'is_active' => false,
            'starts_at' => $now->copy()->subDays(7), 'ends_at' => $now->copy()->addDays(30),
        ]);
        $vip->targets()->create(['target_type' => 'category', 'target_id' => Category::where('name_en', 'Vitamins & Supplements')->value('id')]);
        $vip->customers()->attach($sara->id);

        $vipCoupon = Coupon::create([
            'code' => 'SARA-VIT', 'name' => 'Vitamins coupon for Sara', 'discount_value' => 15, 'is_active' => true,
            'start_at' => $now->copy()->subDays(1), 'end_at' => $now->copy()->addDays(30), 'used_count' => 0, 'usage_limit' => 1,
            'customer_scope' => DiscountRule::SCOPE_SPECIFIC, 'first_order_only' => false,
        ]);
        $vipCoupon->targets()->create(['target_type' => 'category', 'target_id' => Category::where('name_en', 'Vitamins & Supplements')->value('id')]);
        $vipCoupon->customers()->attach($sara->id);

        $this->history();
    }

    /** Redemptions and snapshots for the demo orders, as checkout records them. */
    private function history(): void
    {
        // One demo line was bought at the demo flash sale's price (the sale's limits count it).
        $sale = FlashSale::with('items')->orderBy('id')->first();
        if ($sale && !OrderItem::whereNotNull('flash_sale_id')->exists()) {
            OrderItem::where('is_gift', false)->whereIn('product_id', $sale->items->pluck('product_id'))
                ->whereHas('order', fn ($q) => $q->where('status', '!=', 'canceled'))->orderBy('id')->first()
                ?->forceFill(['flash_sale_id' => $sale->id])->save();
        }

        foreach (Order::withoutGlobalScopes()->with('items')->get() as $order) {
            $status = match ($order->status) {
                'canceled' => CouponRedemption::CONSUMED, // demo orders kept their coupon use (as recorded)
                'completed' => CouponRedemption::CONSUMED,
                default => CouponRedemption::RESERVED,
            };

            if ($order->coupon_id && ($coupon = Coupon::find($order->coupon_id))) {
                CouponRedemption::create([
                    'coupon_id' => $coupon->id, 'order_id' => $order->id, 'user_id' => $order->user_id, 'code' => $coupon->code,
                    'status' => $status, 'discount_amount' => $order->discount_amount ?? 0, 'reserved_at' => $order->created_at,
                    'consumed_at' => $status === CouponRedemption::CONSUMED ? $order->updated_at : null,
                ]);
                $order->forceFill(['coupon_code' => $coupon->code, 'coupon_discount_amount' => $order->discount_amount ?? 0])->save();
                OrderPromotion::create([
                    'order_id' => $order->id, 'source' => OrderPromotion::SOURCE_COUPON, 'coupon_id' => $coupon->id, 'promotion_type' => 'coupon',
                    'name' => $coupon->code, 'code' => $coupon->code, 'eligible_amount' => $order->subtotal ?? 0, 'discount_amount' => $order->discount_amount ?? 0,
                ]);
            }

            // Flash-priced lines are counted toward their sale's limits, as checkout records them.
            foreach ($order->items->whereNotNull('flash_sale_id')->where('is_gift', false) as $line) {
                FlashSaleRedemption::firstOrCreate(['order_item_id' => $line->id], [
                    'flash_sale_id' => $line->flash_sale_id, 'order_id' => $order->id, 'user_id' => $order->user_id,
                    'product_id' => $line->product_id, 'product_size_id' => $line->product_size_id, 'quantity' => $line->quantity,
                    'status' => $order->status === 'canceled' ? FlashSaleRedemption::RELEASED : ($order->status === 'completed' ? FlashSaleRedemption::CONSUMED : FlashSaleRedemption::RESERVED),
                ]);
            }

            $gift = $order->items->firstWhere('is_gift', true);
            if ($order->gift_rule_id) {
                PromotionRedemption::create([
                    'discount_rule_id' => $order->gift_rule_id, 'order_id' => $order->id, 'user_id' => $order->user_id,
                    'status' => $order->status === 'canceled' ? PromotionRedemption::RELEASED : ($order->status === 'completed' ? PromotionRedemption::CONSUMED : PromotionRedemption::RESERVED),
                    'reward_quantity' => $gift?->quantity ?? 0,
                ]);
                OrderPromotion::create([
                    'order_id' => $order->id, 'source' => OrderPromotion::SOURCE_PROMOTION, 'discount_rule_id' => $order->gift_rule_id,
                    'promotion_type' => DiscountRule::TYPE_BUY_X_GET_Y, 'name' => DiscountRule::find($order->gift_rule_id)?->name,
                    'reward_quantity' => $gift?->quantity ?? 0, 'details' => ['gift_description' => $order->gift_description],
                ]);
            }
        }
    }
}
