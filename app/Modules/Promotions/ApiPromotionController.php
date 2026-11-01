<?php

namespace App\Modules\Promotions;

use App\Http\Controllers\Controller;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\PromotionReward;
use App\Modules\Promotions\Engine\PromotionCatalog;
use App\Support\StoreClock;

/**
 * GET /api/v1/user/promotions — the offers running now, for an "offers" screen: what each one
 * is, what it applies to, its thresholds and rewards, and the live flash sales. Read-only and
 * descriptive: prices themselves always come from the engine (product, cart and checkout APIs).
 * Promotions for specific customers only are not listed.
 */
class ApiPromotionController extends Controller
{
    public function index(PromotionCatalog $catalog)
    {
        $now = StoreClock::now();

        $promotions = $catalog->promotions($now)
            ->filter(fn (DiscountRule $p) => in_array($p->customer_scope, [null, DiscountRule::SCOPE_ALL, DiscountRule::SCOPE_NEW, DiscountRule::SCOPE_RETURNING], true)
                && (!$p->campaign || $p->campaign->isLive($now)))
            ->map(fn (DiscountRule $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'type' => $p->discount_type,
                'value' => $p->discount_value !== null ? (float) $p->discount_value : null,
                'buy_quantity' => $p->discount_type === DiscountRule::TYPE_BUY_X_GET_Y ? (int) $p->min_quantity : null,
                'minimum_spend' => $p->min_amount !== null ? (float) $p->min_amount : null,
                'minimum_order' => $p->min_order_amount !== null ? (float) $p->min_order_amount : null,
                'repeatable' => (bool) $p->repeatable,
                'customers' => $p->customer_scope ?? DiscountRule::SCOPE_ALL,
                'starts_at' => $p->startsAt()?->toDateTimeString(),
                'ends_at' => $p->endsAt()?->toDateTimeString(),
                'applies_to' => $p->targets->where('is_gift', false)->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id, 'excluded' => (bool) $t->is_excluded])->values(),
                'rewards' => $p->rewards->map(fn (PromotionReward $r) => [
                    'type' => $r->reward_type,
                    'minimum_spend' => $r->threshold_amount !== null ? (float) $r->threshold_amount : ($p->min_amount !== null ? (float) $p->min_amount : null),
                    'product_id' => $r->product_id,
                    'product_size_id' => $r->product_size_id,
                    'product_name' => $r->product?->name,
                    'quantity' => $r->quantity,
                    'value' => $r->value !== null ? (float) $r->value : null,
                ])->values(),
            ])->values();

        $flashSales = $catalog->flashSales($now)->map(fn (FlashSale $sale) => [
            'id' => $sale->id,
            'name' => $sale->name,
            'type' => $sale->discount_type,
            'value' => (float) $sale->discount_value,
            'starts_at' => $sale->starts_at->toDateTimeString(),
            'ends_at' => $sale->ends_at->toDateTimeString(),
            'product_ids' => $sale->items->pluck('product_id')->unique()->values(),
        ])->values();

        return response()->json(['success' => true, 'data' => ['promotions' => $promotions, 'flash_sales' => $flashSales]]);
    }
}
