<?php

namespace App\Providers;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DiscountRule;
use App\Models\DiscountRuleTarget;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromotionCampaign;
use App\Models\PromotionRedemption;
use App\Models\PromotionReward;
use App\Modules\Promotions\Engine\EligibilityChecker;
use App\Modules\Promotions\Engine\PromotionCatalog;
use App\Modules\Promotions\Engine\PromotionEngine;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the promotion engine: one engine instance per process, and its per-second caches
 * flushed whenever promotion state or usage changes, so an admin edit or a placed order is
 * visible to the very next price calculation.
 */
class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromotionEngine::class);
    }

    public function boot(): void
    {
        $flushCatalog = function () {
            PromotionCatalog::flush();
            EligibilityChecker::flush();
            Product::flushActiveDiscountCache();
        };

        foreach ([DiscountRule::class, DiscountRuleTarget::class, PromotionReward::class, PromotionCampaign::class, FlashSale::class, FlashSaleItem::class, Coupon::class] as $model) {
            $model::saved($flushCatalog);
            $model::deleted($flushCatalog);
            if (method_exists($model, 'restored')) {
                $model::restored($flushCatalog);
            }
        }

        $flushUsage = fn () => EligibilityChecker::flush();
        foreach ([Order::class, OrderItem::class, PromotionRedemption::class, CouponRedemption::class] as $model) {
            $model::saved($flushUsage);
        }
    }
}
