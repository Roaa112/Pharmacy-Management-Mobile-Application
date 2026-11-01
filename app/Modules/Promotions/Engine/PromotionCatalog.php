<?php

namespace App\Modules\Promotions\Engine;

use App\Models\DiscountRule;
use App\Models\FlashSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The promotions and flash sales live at a given store-clock time, loaded with everything the
 * strategies need (targets, rewards with products and sizes, campaign, customer list) in a
 * constant number of queries.
 *
 * Cached per store-clock second, so every price in one request uses the same promotion state
 * and a listing of 100 products does not load the rules 100 times. Saving any promotion model
 * flushes the cache (see PromotionsServiceProvider), so admin changes apply immediately.
 */
class PromotionCatalog
{
    private static array $cache = [];

    /** @return Collection<int, DiscountRule> sorted by precedence: priority desc, id asc */
    public function promotions(Carbon $now): Collection
    {
        return $this->remember('promotions', $now, fn () => DiscountRule::query()
            ->with(['targets', 'rewards.product', 'rewards.size', 'campaign', 'customers:id'])
            ->where('is_active', true)
            ->where('starts_at', '<=', $now->toDateTimeString())
            ->where('ends_at', '>=', $now->toDateTimeString())
            ->get()
            ->sort(fn (DiscountRule $a, DiscountRule $b) => [$b->priority, $a->id] <=> [$a->priority, $b->id])
            ->values());
    }

    /** @return Collection<int, FlashSale> live flash sales with their items, by precedence */
    public function flashSales(Carbon $now): Collection
    {
        return $this->remember('flash', $now, fn () => FlashSale::query()
            ->with('items')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now->toDateTimeString()))
            ->get()
            ->filter(fn (FlashSale $sale) => $sale->isLive($now))
            ->sort(fn (FlashSale $a, FlashSale $b) => [$b->priority, $a->id] <=> [$a->priority, $b->id])
            ->values());
    }

    /**
     * The live promotions plus, for a dashboard preview, one promotion treated as live (it may be
     * a draft, scheduled or inactive). It replaces its stored version.
     */
    public function promotionsFor(PricingInput $input): Collection
    {
        $promotions = $this->promotions($input->now);

        if (!$input->simulate) {
            return $promotions;
        }

        $simulated = $input->simulate->loadMissing(['targets', 'rewards.product', 'rewards.size', 'campaign', 'customers:id']);

        return $promotions->reject(fn ($p) => $p->id === $simulated->id)->push($simulated)
            ->sort(fn (DiscountRule $a, DiscountRule $b) => [$b->priority, $a->id] <=> [$a->priority, $b->id])
            ->values();
    }

    public static function flush(): void
    {
        static::$cache = [];
    }

    private function remember(string $key, Carbon $now, callable $load): Collection
    {
        $second = $now->toDateTimeString();

        if ((static::$cache[$key]['at'] ?? null) !== $second) {
            static::$cache[$key] = ['at' => $second, 'value' => $load()];
        }

        return static::$cache[$key]['value'];
    }
}
