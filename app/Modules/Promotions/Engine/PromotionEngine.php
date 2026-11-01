<?php

namespace App\Modules\Promotions\Engine;

use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\PromotionReward;
use App\Models\User;
use App\Modules\Promotions\Engine\Strategies\BuyXGetYStrategy;
use App\Modules\Promotions\Engine\Strategies\CouponStrategy;
use App\Modules\Promotions\Engine\Strategies\FixedAmountDiscountStrategy;
use App\Modules\Promotions\Engine\Strategies\FlashSaleStrategy;
use App\Modules\Promotions\Engine\Strategies\FreeDeliveryStrategy;
use App\Modules\Promotions\Engine\Strategies\ItemCandidate;
use App\Modules\Promotions\Engine\Strategies\OrderContext;
use App\Modules\Promotions\Engine\Strategies\OrderOutcome;
use App\Modules\Promotions\Engine\Strategies\PercentageDiscountStrategy;
use App\Modules\Promotions\Engine\Strategies\SpendXGetYStrategy;
use App\Support\StoreClock;
use Illuminate\Support\Collection;

/**
 * The one place that prices products, carts and orders. Product listings and details
 * (Product::priceForSize), the cart, the coupon preview, checkout, the customer promotions
 * API and the dashboard simulator all call it; nothing else calculates a promotion.
 *
 * Calculation order (documented in docs/marketing-promotions.md):
 *   1. every customer line starts at its size's database price;
 *   2. item promotions: flash sales, percentage and fixed discounts, one winner per line
 *      unless explicitly stackable (ConflictResolver);
 *   3. the coupon's eligible items and its items discount (tentative);
 *   4. order promotions in precedence order (priority desc, id asc): Buy X get Y, Spend X
 *      get Y, free delivery — eligibility, exclusivity, duplicate rewards, gift stock;
 *   5. order discounts: automatic vs a non-combinable coupon (larger wins);
 *   6. delivery: the best free-delivery offer, then the coupon's delivery part;
 *   7. totals (never negative).
 *
 * Deterministic: the same lines, customer, coupon, delivery fee, stock and promotion state
 * always give the same result, so the cart preview and checkout agree.
 */
class PromotionEngine
{
    public function __construct(
        private PromotionCatalog $catalog,
        private EligibilityChecker $eligibility,
        private ConflictResolver $resolver,
        private FlashSaleStrategy $flash,
        private PercentageDiscountStrategy $percent,
        private FixedAmountDiscountStrategy $fixed,
        private BuyXGetYStrategy $buyXGetY,
        private SpendXGetYStrategy $spendXGetY,
        private FreeDeliveryStrategy $freeDelivery,
        private CouponStrategy $coupons,
    ) {
    }

    /**
     * Catalogue price of one unit (listings, product page): item promotions only, without cart
     * conditions. Returns [unit minor, winning promotion or null].
     *
     * @return array{0: int, 1: ?array{type: string, value: mixed, source: string, id: int}}
     */
    public function catalogPrice(Product $product, ProductSize $size, ?User $user = null): array
    {
        $input = new PricingInput($user, null, null, StoreClock::now(), catalogOnly: true);
        $line = new PricedLine('catalog', $product, $size, 1, \App\Support\Money::toMinor($size->price), \App\Support\Money::toMinor($size->price));

        $this->priceLine($line, $input, $this->catalog->promotions($input->now), $this->catalog->flashSales($input->now), 0, null);

        $winner = $line->applied[0] ?? null;

        return [$line->unit, $winner ? ['type' => $winner['type'], 'value' => $winner['value'], 'source' => $winner['source'], 'id' => $winner['id']] : null];
    }

    public function evaluate(PricingInput $input): PricingResult
    {
        $result = new PricingResult();
        $promotions = $this->catalog->promotionsFor($input);
        $flashSales = $this->catalog->flashSales($input->now);

        if ($input->forCheckout) {
            $this->lockLimited($promotions, $flashSales);
        }

        // 1. Database prices.
        foreach ($input->lines as $line) {
            $unit = \App\Support\Money::toMinor($line['size']->price);
            $result->lines[] = new PricedLine($line['key'], $line['product'], $line['size'], $line['quantity'], $unit, $unit);
        }
        $result->originalSubtotal = array_sum(array_map(fn (PricedLine $l) => $l->originalTotal(), $result->lines));

        // 2. Item promotions.
        foreach ($result->lines as $line) {
            $this->priceLine($line, $input, $promotions, $flashSales, $result->originalSubtotal, $result);
        }
        $result->subtotal = array_sum(array_map(fn (PricedLine $l) => $l->total(), $result->lines));
        $this->recordItemPromotions($result);

        $result->deliveryKnown = $input->deliveryFeeMinor !== null;
        $result->deliveryFee = $input->deliveryFeeMinor ?? 0;

        // 3. Coupon (tentative, items only).
        $coupon = $this->coupons->evaluate($input, $result->lines);
        if ($coupon && !isset($coupon['eligible'])) {
            $result->couponRejection = $this->rejection('coupon', $coupon['coupon']?->id ?? 0, 'coupon', $coupon['coupon']?->code ?? (string) $input->couponCode, $coupon['reason'], $coupon['params']);
            $coupon = null;
        }

        $context = new OrderContext($result->lines, $input, $result->originalSubtotal, $coupon ? $this->couponShares($result->lines, $coupon) : []);

        // 4. Order promotions.
        $offers = $this->orderPromotions($promotions, $context, $input, $result);

        // 5. Order discounts.
        $this->applyOrderDiscounts($offers, $coupon, $result);

        // 6. Delivery.
        $this->applyDelivery($offers, $result);

        if ($coupon && $result->coupon) {
            $deliveryLeft = $result->deliveryFee - $result->deliveryDiscount;
            $itemsRoom = max(0, $result->subtotal - $result->promotionDiscount);
            $total = $this->coupons->discount($result->coupon, min($coupon['eligible'], $itemsRoom), $deliveryLeft);
            $result->couponDiscount = min($total, $itemsRoom + $deliveryLeft);
            $result->couponItemsDiscount = min($result->couponDiscount, $coupon['items_discount'], $itemsRoom);
            $result->couponDeliveryDiscount = max(0, $result->couponDiscount - $result->couponItemsDiscount);
            $result->applied[] = [
                'source' => 'coupon', 'id' => $result->coupon->id, 'type' => 'coupon', 'name' => $result->coupon->name ?: $result->coupon->code,
                'code' => $result->coupon->code, 'eligible' => $coupon['eligible'], 'discount' => $result->couponDiscount,
                'delivery_discount' => $result->couponDeliveryDiscount, 'reward_quantity' => 0,
                'details' => ['coupon_type' => $result->coupon->type, 'value' => (float) $result->coupon->discount_value, 'lines' => $coupon['lines']],
                'model' => $result->coupon,
            ];
        }

        // 7. Order-level promotions that ended up giving something.
        foreach ($offers as $offer) {
            if ($offer['used']) {
                $result->applied[] = $offer['applied'] + ['discount' => $offer['discount_used'], 'delivery_discount' => $offer['delivery_used']];
            } elseif ($offer['rejected_reason']) {
                $result->rejected[] = $this->rejection('promotion', $offer['promotion']->id, $offer['promotion']->discount_type, $offer['promotion']->name, $offer['rejected_reason'], []);
            }
        }

        return $result;
    }

    /**
     * Item promotions for one line: candidates from live flash sales and percentage/fixed rules,
     * resolved by the ConflictResolver.
     */
    private function priceLine(PricedLine $line, PricingInput $input, Collection $promotions, Collection $flashSales, int $originalSubtotal, ?PricingResult $result): void
    {
        $candidates = [];

        foreach ($flashSales as $sale) {
            $candidate = $this->flash->candidate($sale, $line, $input);
            $this->collect($candidate, $candidates, $result, 'flash_sale', $sale->id, 'flash', $sale->name, $line);
        }

        foreach ($promotions as $promotion) {
            $strategy = match ($promotion->discount_type) {
                DiscountRule::TYPE_PERCENT => $this->percent,
                DiscountRule::TYPE_FIXED => $this->fixed,
                default => null,
            };
            if ($strategy) {
                $candidate = $strategy->candidate($promotion, $line, $input, $originalSubtotal);
                $this->collect($candidate, $candidates, $result, 'promotion', $promotion->id, $promotion->discount_type, $promotion->name, $line);
            }
        }

        if (!$candidates) {
            return;
        }

        [$applied, $rejected] = $this->resolver->resolveItem($candidates);

        foreach ($applied as $candidate) {
            $before = $line->unit;
            $line->unit = max(0, min($before, ($candidate->apply)($before)));
            $line->applied[] = [
                'source' => $candidate->source, 'id' => $candidate->id, 'type' => $candidate->type, 'name' => $candidate->name,
                'unit_discount' => $before - $line->unit,
                'value' => $candidate->model->discount_value ?? null,
            ];
            if ($candidate->source === 'flash_sale') {
                $line->flashSaleId = $candidate->id;
            } else {
                $line->promotionId ??= $candidate->id;
            }
        }

        if ($result) {
            foreach ($rejected as [$candidate, $reason]) {
                $result->rejected[] = $this->rejection($candidate->source, $candidate->id, $candidate->type, $candidate->name, $reason, [], ['line' => $line->key]);
            }
        }
    }

    private function collect(ItemCandidate|array|null $candidate, array &$candidates, ?PricingResult $result, string $source, int $id, string $type, string $name, PricedLine $line): void
    {
        if ($candidate instanceof ItemCandidate) {
            $candidates[] = $candidate;
        } elseif (is_array($candidate) && $result) {
            $result->rejected[] = $this->rejection($source, $id, $type, $name, $candidate[0], $candidate[1], ['line' => $line->key]);
        }
    }

    /** One "applied" entry per item promotion, summed over its lines. */
    private function recordItemPromotions(PricingResult $result): void
    {
        $byPromotion = [];

        foreach ($result->lines as $line) {
            foreach ($line->applied as $applied) {
                $key = $applied['source'].':'.$applied['id'];
                $byPromotion[$key] ??= ['source' => $applied['source'], 'id' => $applied['id'], 'type' => $applied['type'], 'name' => $applied['name'],
                    'eligible' => 0, 'discount' => 0, 'delivery_discount' => 0, 'reward_quantity' => 0, 'details' => ['lines' => []]];
                $byPromotion[$key]['eligible'] += $line->originalTotal();
                $byPromotion[$key]['discount'] += $applied['unit_discount'] * $line->quantity;
                $byPromotion[$key]['details']['lines'][] = $line->key;
            }
        }

        array_push($result->applied, ...array_values($byPromotion));
    }

    /**
     * Gift, spend and free-delivery promotions in precedence order, with exclusivity, duplicate
     * rewards and gift stock.
     *
     * @return list<array> offers: promotion, outcome, gifts granted, discount/delivery candidates
     */
    private function orderPromotions(Collection $promotions, OrderContext $context, PricingInput $input, PricingResult $result): array
    {
        $offers = [];
        $exclusiveTaken = false;
        $allocated = [];      // size id => gift units already given in this evaluation
        $rewardOwners = [];   // size id => stackable flag of the promotion that gives it

        foreach ($promotions as $promotion) {
            $strategy = match ($promotion->discount_type) {
                DiscountRule::TYPE_BUY_X_GET_Y => $this->buyXGetY,
                DiscountRule::TYPE_SPEND_X_GET_Y => $this->spendXGetY,
                DiscountRule::TYPE_FREE_DELIVERY => $this->freeDelivery,
                default => null,
            };
            if (!$strategy) {
                continue;
            }

            $outcome = $strategy->evaluate($promotion, $context);
            if (!$outcome->relevant) {
                continue;
            }

            $reason = $this->eligibility->promotion($promotion, $input)
                ?? $this->eligibility->orderBounds($promotion, $result->originalSubtotal)
                ?? $outcome->rejection;

            if ($reason) {
                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, $reason[0], $reason[1], $outcome->details);
                if ($outcome->hint && !$this->eligibility->promotion($promotion, $input)) {
                    $result->hints[] = $outcome->hint;
                }
                continue;
            }

            if ($outcome->hint) {
                $result->hints[] = $outcome->hint;
            }

            if ($exclusiveTaken || ($promotion->is_exclusive && $offers)) {
                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name,
                    $exclusiveTaken ? 'blocked_by_exclusive' : 'exclusive_conflict', [], $outcome->details);
                continue;
            }

            $granted = $this->grantGifts($promotion, $outcome, $input, $result, $allocated, $rewardOwners);

            $offer = [
                'promotion' => $promotion,
                'outcome' => $outcome,
                'gift_units' => $granted,
                'used' => $granted > 0,
                'discount_used' => 0,
                'delivery_used' => 0,
                'rejected_reason' => null,
                'applied' => [
                    'source' => 'promotion', 'id' => $promotion->id, 'type' => $promotion->discount_type, 'name' => $promotion->name,
                    'eligible' => $outcome->eligible, 'reward_quantity' => $granted, 'details' => $outcome->details, 'model' => $promotion,
                ],
            ];

            if ($granted === 0 && !$outcome->discounts && !$outcome->delivery) {
                // Every gift failed (stock, duplicate); the reasons were recorded per reward.
                continue;
            }

            $offers[] = $offer;
            if ($promotion->is_exclusive) {
                $exclusiveTaken = true;
            }
        }

        return $offers;
    }

    /**
     * Give the free-product rewards of one promotion, within stock. Stock for a gift = sellable
     * units − units of that size the customer is buying − gift units already given.
     *
     * @return int gift units granted
     */
    private function grantGifts(DiscountRule $promotion, OrderOutcome $outcome, PricingInput $input, PricingResult $result, array &$allocated, array &$rewardOwners): int
    {
        $granted = 0;
        $maxUnits = (int) config('promotions.max_gift_units_per_reward', 100);

        foreach ($outcome->gifts as ['reward' => $reward, 'quantity' => $requested]) {
            $requested = min($requested, $maxUnits);
            $product = $reward->product;

            if (!$product || $product->trashed()) {
                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, 'gift_unavailable', [], ['reward_id' => $reward->id]);
                continue;
            }

            $sizes = $reward->product_size_id
                ? collect([$reward->size])->filter(fn ($s) => $s && $s->product_id === $product->id)
                : $product->sizes->sortBy('id')->values();

            if ($sizes->isEmpty()) {
                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, 'gift_unavailable', [], ['reward_id' => $reward->id]);
                continue;
            }

            // The same product from two non-stackable promotions: only the first one gives it.
            $duplicate = $sizes->first(fn ($s) => isset($rewardOwners[$s->id]) && !($rewardOwners[$s->id] && $promotion->is_stackable));
            if ($duplicate) {
                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, 'duplicate_reward', [], ['reward_id' => $reward->id]);
                continue;
            }

            $available = fn (ProductSize $size) => $input->stockOf($size)
                - array_sum(array_map(fn (PricedLine $l) => $l->size->id === $size->id ? $l->quantity : 0, $result->lines))
                - ($allocated[$size->id] ?? 0);

            $size = $sizes->first(fn ($s) => $available($s) >= $requested);
            $quantity = $requested;

            if (!$size) {
                $best = $sizes->sortByDesc(fn ($s) => $available($s))->first();
                $left = max(0, $available($best));
                $fallback = $promotion->gift_fallback ?: DiscountRule::FALLBACK_PARTIAL;

                if ($fallback === DiscountRule::FALLBACK_BLOCK) {
                    $result->blocking[] = __('promotions.gift_blocked');
                }

                if ($fallback !== DiscountRule::FALLBACK_PARTIAL || $left <= 0) {
                    $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, 'gift_out_of_stock', [], ['reward_id' => $reward->id, 'requested' => $requested]);
                    continue;
                }

                $result->rejected[] = $this->rejection('promotion', $promotion->id, $promotion->discount_type, $promotion->name, 'gift_partially_available',
                    ['available' => $left, 'requested' => $requested], ['reward_id' => $reward->id]);
                $size = $best;
                $quantity = $left;
            }

            $allocated[$size->id] = ($allocated[$size->id] ?? 0) + $quantity;
            $rewardOwners[$size->id] = (bool) $promotion->is_stackable;
            $granted += $quantity;

            $result->gifts[] = [
                'gift_key' => "p{$promotion->id}:r{$reward->id}",
                'promotion' => $promotion,
                'reward' => $reward,
                'size' => $size->setRelation('product', $product),
                'quantity' => $quantity,
                'requested_quantity' => $requested,
                'original_unit' => \App\Support\Money::toMinor($size->price),
                'status' => $quantity < $requested ? 'partial' : 'granted',
            ];
        }

        return $granted;
    }

    /** Automatic order discounts vs each other and vs a non-combinable coupon. */
    private function applyOrderDiscounts(array &$offers, ?array $coupon, PricingResult $result): void
    {
        $automatic = [];
        foreach ($offers as $index => $offer) {
            $amount = array_sum(array_column($offer['outcome']->discounts, 'amount'));
            if ($amount > 0) {
                $automatic[] = ['key' => (string) $index, 'amount' => $amount, 'stackable' => (bool) $offer['promotion']->is_stackable];
            }
        }

        [$kept, $rejected] = $this->resolver->resolveAutomaticDiscounts($automatic);
        $amounts = array_column($automatic, 'amount', 'key');
        $automaticTotal = array_sum(array_map(fn ($key) => $amounts[$key], $kept));

        if ($coupon && $kept && !$coupon['coupon']->combines_with_promotions) {
            if ($this->resolver->couponBeatsAutomatic($coupon['items_discount'], $automaticTotal)) {
                $rejected = array_merge($rejected, $kept);
                $kept = [];
                $automaticTotal = 0;
            } else {
                $result->couponRejection = $this->rejection('coupon', $coupon['coupon']->id, 'coupon', $coupon['coupon']->code, 'conflict_better_offer', []);
                $coupon = null;
            }
        }

        $room = $result->subtotal;
        foreach ($kept as $key) {
            $used = min($amounts[$key], $room);
            $room -= $used;
            $offers[(int) $key]['discount_used'] = $used;
            $offers[(int) $key]['used'] = true;
            $result->promotionDiscount += $used;
        }

        foreach ($rejected as $key) {
            if (!$offers[(int) $key]['used'] && !$offers[(int) $key]['outcome']->delivery) {
                $offers[(int) $key]['rejected_reason'] = 'conflict_better_offer';
            }
        }

        $result->coupon = $coupon['coupon'] ?? null;
    }

    private function applyDelivery(array &$offers, PricingResult $result): void
    {
        $candidates = [];
        foreach ($offers as $index => $offer) {
            foreach ($offer['outcome']->delivery as $delivery) {
                $waived = $delivery['cap'] === null ? $result->deliveryFee : min($result->deliveryFee, $delivery['cap']);
                $candidates[] = ['key' => (string) $index, 'amount' => $waived];
                break;
            }
        }

        $winner = $this->resolver->resolveDelivery($candidates);

        foreach ($candidates as $candidate) {
            $offer = &$offers[(int) $candidate['key']];
            if ($candidate['key'] === $winner) {
                $offer['delivery_used'] = $candidate['amount'];
                $offer['used'] = true;
                $offer['applied']['details']['free_delivery'] = true;
                if (!$result->deliveryKnown) {
                    $offer['applied']['details']['pending_delivery'] = true;
                }
                $result->deliveryDiscount = $candidate['amount'];
            } elseif (!$offer['used']) {
                $offer['rejected_reason'] = 'delivery_already_free';
            }
            unset($offer);
        }
    }

    /**
     * The coupon's items discount spread over its eligible lines in proportion to their totals
     * (largest remainder, so the shares add up exactly).
     *
     * @return array<string, int>
     */
    private function couponShares(array $lines, array $coupon): array
    {
        $eligible = array_values(array_filter($lines, fn (PricedLine $l) => in_array((string) $l->key, $coupon['lines'], true)));
        $base = array_sum(array_map(fn (PricedLine $l) => $l->total(), $eligible));
        if ($base <= 0 || $coupon['items_discount'] <= 0) {
            return [];
        }

        $shares = [];
        $remainders = [];
        foreach ($eligible as $line) {
            $exact = $coupon['items_discount'] * $line->total();
            $shares[(string) $line->key] = intdiv($exact, $base);
            $remainders[(string) $line->key] = $exact % $base;
        }

        arsort($remainders);
        $left = $coupon['items_discount'] - array_sum($shares);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            }
            $shares[$key]++;
        }

        return $shares;
    }

    /** Lock promotions and flash sales whose limits are counted, so concurrent checkouts wait. */
    private function lockLimited(Collection $promotions, Collection $flashSales): void
    {
        $promotionIds = $promotions->filter(fn ($p) => $p->usage_limit || $p->per_customer_limit)->pluck('id')->sort()->values();
        if ($promotionIds->isNotEmpty()) {
            DiscountRule::whereIn('id', $promotionIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $saleIds = $flashSales->filter(fn ($s) => $s->quantity_limit || $s->per_customer_limit || $s->items->contains(fn ($i) => $i->quantity_limit))
            ->pluck('id')->sort()->values();
        if ($saleIds->isNotEmpty()) {
            FlashSale::whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    private function rejection(string $source, int $id, string $type, string $name, string $reason, array $params, array $details = []): array
    {
        $key = "promotions.reasons.{$reason}";
        $message = __($key, $params, function_exists('api_locale') ? api_locale() : null);

        return [
            'source' => $source,
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'reason' => $reason,
            'message' => $message === $key ? $reason : $message,
            'details' => $details,
        ];
    }
}
