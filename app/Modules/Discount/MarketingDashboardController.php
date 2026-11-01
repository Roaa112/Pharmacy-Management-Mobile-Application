<?php

namespace App\Modules\Discount;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\OpeningAd;
use App\Models\Order;
use App\Models\OrderPromotion;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Marketing → Overview: what is live, scheduled and ending soon, and the performance of every
 * promotion, flash sale and coupon over a date range (from the order snapshots, so edited or
 * archived promotions still report what they did).
 */
class MarketingDashboardController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $now = StoreClock::now();

        $orders = Order::whereBetween('created_at', [$from, $to])->where('status', '!=', 'canceled');
        $snapshots = OrderPromotion::query()->whereIn('order_id', (clone $orders)->select('id'));

        $performance = (clone $snapshots)
            ->selectRaw('source, discount_rule_id, flash_sale_id, coupon_id, max(name) as name, max(promotion_type) as promotion_type, count(distinct order_id) as orders,
                sum(discount_amount) as discount, sum(delivery_discount_amount) as delivery_discount, sum(reward_quantity) as gift_units, sum(eligible_amount) as eligible')
            ->groupBy('source', 'discount_rule_id', 'flash_sale_id', 'coupon_id')
            ->orderByDesc('orders')
            ->limit(50)
            ->get();

        $nowString = $now->toDateTimeString();

        return view('dashboard.marketing.index', [
            'from' => $from,
            'to' => $to,
            'kpis' => [
                'live_promotions' => DiscountRule::where('is_active', true)->where('starts_at', '<=', $nowString)->where('ends_at', '>=', $nowString)->count(),
                'scheduled' => DiscountRule::where('is_active', true)->where('starts_at', '>', $nowString)->count(),
                'live_flash' => FlashSale::with('items')->where('is_active', true)->get()->filter(fn ($s) => $s->isLive($now))->count(),
                'active_coupons' => Coupon::where('is_active', true)->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $nowString))->count(),
                'orders_with_offers' => (clone $snapshots)->distinct('order_id')->count('order_id'),
                'orders' => (clone $orders)->count(),
                'savings' => (float) (clone $orders)->sum('total_savings'),
                'coupon_uses' => CouponRedemption::whereBetween('created_at', [$from, $to])->whereIn('status', CouponRedemption::COUNTING)->count(),
                'live_banners' => Banner::visible()->count(),
                'live_ads' => OpeningAd::visible()->count(),
            ],
            'performance' => $performance,
            'endingSoon' => DiscountRule::where('is_active', true)->whereBetween('ends_at', [$nowString, $now->copy()->addDays(3)->toDateTimeString()])->orderBy('ends_at')->limit(10)->get(),
        ]);
    }
}
