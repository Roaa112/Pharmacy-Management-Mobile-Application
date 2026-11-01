<?php

namespace App\Modules\Promotions\Services;

use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\PromotionReward;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Domain-level validation of a promotion's configuration, beyond the form rules. Errors are
 * critical: an active promotion cannot be saved with them (activation is blocked). Warnings
 * are shown in the dashboard and the preview but do not block.
 *
 * Validation happens at five levels: the dashboard request (form rules), here (domain), the
 * engine's eligibility at cart time, again at checkout under locks, and at order creation
 * (blocking gift fallback, expected total).
 */
class PromotionValidator
{
    /** @return array{errors: list<string>, warnings: list<string>} */
    public function check(DiscountRule $promotion): array
    {
        $promotion->loadMissing(['targets', 'rewards.product', 'rewards.size', 'customers:id', 'campaign']);
        $errors = [];
        $warnings = [];

        $starts = $promotion->startsAt();
        $ends = $promotion->endsAt();
        if (!$starts || !$ends) {
            $errors[] = 'Start and end dates are required.';
        } elseif ($ends->lte($starts)) {
            $errors[] = 'The end must be after the start.';
        }

        $included = $promotion->targets->where('is_gift', false)->where('is_excluded', false);
        foreach ($promotion->targets->where('is_gift', false) as $target) {
            if (!$this->targetExists($target->target_type, (int) $target->target_id)) {
                $errors[] = ucfirst($target->target_type)." #{$target->target_id} does not exist.";
            }
        }

        if ($promotion->customer_scope === DiscountRule::SCOPE_SPECIFIC && $promotion->customers->isEmpty()) {
            $errors[] = 'Choose at least one customer, or change who the promotion is for.';
        }
        if ($promotion->campaign && in_array($promotion->campaign->state(), ['expired', 'inactive'], true)) {
            $warnings[] = 'Its campaign is '.$promotion->campaign->state().': the promotion will not apply until the campaign runs.';
        }
        if ($promotion->usage_limit !== null && $promotion->usage_limit < 1) {
            $errors[] = 'The usage limit must be at least 1.';
        }
        if ($promotion->min_order_amount !== null && $promotion->max_order_amount !== null && Money::toMinor($promotion->max_order_amount) < Money::toMinor($promotion->min_order_amount)) {
            $errors[] = 'The maximum order amount is below the minimum.';
        }

        $value = Money::toMinor($promotion->discount_value);

        switch ($promotion->discount_type) {
            case DiscountRule::TYPE_PERCENT:
                if ($value <= 0 || $value > 10000) {
                    $errors[] = 'A percentage must be more than 0 and at most 100.';
                }
                if ($included->isEmpty()) {
                    $errors[] = 'Choose what the discount applies to.';
                }
                break;

            case DiscountRule::TYPE_FIXED:
                if ($value <= 0) {
                    $errors[] = 'A fixed discount must be more than 0.';
                }
                if ($included->isEmpty()) {
                    $errors[] = 'Choose what the discount applies to.';
                }
                $free = $this->coveredSizes($promotion)->filter(fn (ProductSize $s) => Money::toMinor($s->price) <= $value);
                if ($free->isNotEmpty()) {
                    $warnings[] = $free->count().' variant(s) cost at most the discount and would be free (price is never below 0).';
                }
                break;

            case DiscountRule::TYPE_BUY_X_GET_Y:
                if ((int) $promotion->min_quantity < 1) {
                    $errors[] = 'Buy quantity must be at least 1.';
                }
                if ($included->isEmpty()) {
                    $errors[] = 'Choose the qualifying products.';
                }
                $rewards = $promotion->rewards->where('reward_type', PromotionReward::FREE_PRODUCT);
                if ($rewards->isEmpty()) {
                    $errors[] = 'Add at least one free product reward.';
                }
                if ($promotion->rewards->where('reward_type', '!=', PromotionReward::FREE_PRODUCT)->isNotEmpty()) {
                    $errors[] = 'Buy X get Y rewards are free products only.';
                }
                break;

            case DiscountRule::TYPE_SPEND_X_GET_Y:
                if ($promotion->rewards->isEmpty()) {
                    $errors[] = 'Add at least one reward.';
                }
                $thresholds = $promotion->rewards->map(fn ($r) => Money::toMinor($r->threshold_amount ?? $promotion->min_amount ?? 0))->unique();
                if ($thresholds->contains(fn ($t) => $t <= 0)) {
                    $errors[] = 'Every reward needs a minimum spend above 0.';
                }
                if ($promotion->repeatable && $thresholds->count() > 1) {
                    $errors[] = 'A repeatable Spend X promotion can only have one tier.';
                }
                foreach ($promotion->rewards as $reward) {
                    $threshold = Money::toMinor($reward->threshold_amount ?? $promotion->min_amount ?? 0);
                    if ($reward->reward_type === PromotionReward::FIXED_DISCOUNT && Money::toMinor($reward->value) >= $threshold && $threshold > 0) {
                        $warnings[] = 'A fixed discount of '.$reward->value.' is at least its minimum spend.';
                    }
                }
                break;

            case DiscountRule::TYPE_FREE_DELIVERY:
                if ($promotion->min_amount === null || Money::toMinor($promotion->min_amount) < 0) {
                    $errors[] = 'Set the minimum spend for free delivery (0 = always).';
                }
                break;

            default:
                $errors[] = 'Unknown promotion type.';
        }

        foreach ($promotion->rewards as $reward) {
            array_push($errors, ...$this->rewardErrors($reward));
            if ($reward->reward_type === PromotionReward::FREE_PRODUCT && $reward->product) {
                $sizes = $reward->size ? collect([$reward->size]) : $reward->product->sizes;
                if ($sizes->sum('stock') <= 0) {
                    $warnings[] = 'The gift '.$reward->label().' is out of stock: it will not be given until restocked.';
                }
            }
        }

        // Legacy gift targets that name a brand or category do not say which product to give.
        if (in_array($promotion->discount_type, DiscountRule::GIFT_TYPES, true)
            && $promotion->targets->where('is_gift', true)->where('target_type', '!=', 'product')->isNotEmpty()
            && $promotion->rewards->isEmpty()) {
            $errors[] = 'The gift is a brand or category: choose the exact product to give.';
        }

        [$conflictErrors, $conflictWarnings] = $this->conflicts($promotion);

        return ['errors' => array_values(array_unique(array_merge($errors, $conflictErrors))), 'warnings' => array_values(array_unique(array_merge($warnings, $conflictWarnings)))];
    }

    /** @return list<string> */
    private function rewardErrors(PromotionReward $reward): array
    {
        $errors = [];

        if (!in_array($reward->reward_type, PromotionReward::TYPES, true)) {
            return ['Unknown reward type.'];
        }

        if ($reward->reward_type === PromotionReward::FREE_PRODUCT) {
            if (!$reward->product || $reward->product->trashed()) {
                $errors[] = 'A reward product does not exist (or was deleted).';
            } elseif ($reward->product_size_id && (!$reward->size || $reward->size->product_id !== $reward->product_id)) {
                $errors[] = 'A reward variant does not belong to its product.';
            }
            if ((int) $reward->quantity < 1) {
                $errors[] = 'Reward quantities must be at least 1.';
            }
        }
        if ($reward->reward_type === PromotionReward::FIXED_DISCOUNT && Money::toMinor($reward->value) <= 0) {
            $errors[] = 'A fixed discount reward must be more than 0.';
        }
        if ($reward->reward_type === PromotionReward::PERCENT_DISCOUNT && (Money::toMinor($reward->value) <= 0 || Money::toMinor($reward->value) > 10000)) {
            $errors[] = 'A percentage reward must be more than 0 and at most 100.';
        }

        return $errors;
    }

    /**
     * Other live or scheduled promotions overlapping in time on the same exact target. Equal
     * priority between two non-stackable item discounts is ambiguous for the admin (the older
     * one would win), so it blocks activation; otherwise the winner is stated as a warning.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function conflicts(DiscountRule $promotion): array
    {
        $errors = [];
        $warnings = [];
        $starts = $promotion->startsAt();
        $ends = $promotion->endsAt();

        if (!$starts || !$ends) {
            return [[], []];
        }

        $others = DiscountRule::with(['targets', 'rewards'])
            ->where('id', '!=', $promotion->id ?? 0)
            ->where('is_active', true)
            ->where('starts_at', '<=', $ends->toDateTimeString())
            ->where('ends_at', '>=', $starts->toDateTimeString())
            ->get();

        $keys = fn (DiscountRule $p) => $p->targets->where('is_gift', false)->where('is_excluded', false)->toBase()->map(fn ($t) => $t->target_type.':'.$t->target_id)->values();
        $mine = $keys($promotion);
        $isItem = in_array($promotion->discount_type, DiscountRule::ITEM_TYPES, true);

        foreach ($others as $other) {
            $shared = $mine->intersect($keys($other));

            if ($isItem && in_array($other->discount_type, DiscountRule::ITEM_TYPES, true) && $shared->isNotEmpty()) {
                if ($promotion->is_stackable && $other->is_stackable) {
                    $warnings[] = "Stacks with “{$other->name}” (#{$other->id}) on ".$shared->implode(', ').'.';
                } elseif ((int) $other->priority === (int) $promotion->priority) {
                    $errors[] = "Conflicts with “{$other->name}” (#{$other->id}) on ".$shared->implode(', ').' with the same priority. Change the priority, the dates or the targets.';
                } else {
                    $winner = (int) $other->priority > (int) $promotion->priority ? $other : $promotion;
                    $warnings[] = "Overlaps “{$other->name}” (#{$other->id}) on ".$shared->implode(', ')."; “{$winner->name}” wins (higher priority).";
                }
            }

            if (in_array($promotion->discount_type, DiscountRule::GIFT_TYPES, true) && in_array($other->discount_type, DiscountRule::GIFT_TYPES, true)) {
                $sameGift = $promotion->rewards->where('reward_type', PromotionReward::FREE_PRODUCT)->pluck('product_id')
                    ->intersect($other->rewards->where('reward_type', PromotionReward::FREE_PRODUCT)->pluck('product_id'));
                if ($sameGift->isNotEmpty() && !($promotion->is_stackable && $other->is_stackable)) {
                    $warnings[] = "“{$other->name}” (#{$other->id}) gives the same gift; only the one with higher precedence gives it in one order.";
                }
            }

            if ($promotion->is_exclusive || $other->is_exclusive) {
                if (!$isItem && !in_array($other->discount_type, DiscountRule::ITEM_TYPES, true)) {
                    $warnings[] = "“{$other->name}” (#{$other->id}) runs at the same time and one of the two is exclusive.";
                }
            }
        }

        if ($isItem && $mine->isNotEmpty()) {
            $productIds = $this->coveredSizes($promotion)->pluck('product_id')->unique();
            $flash = FlashSale::with('items')->where('is_active', true)->get()
                ->filter(fn (FlashSale $sale) => $sale->starts_at->lt($ends) && $sale->ends_at->gt($starts) && $sale->items->pluck('product_id')->intersect($productIds)->isNotEmpty());
            foreach ($flash as $sale) {
                $warnings[] = "Flash sale “{$sale->name}” covers some of these products; during its window it wins over this discount.";
            }
        }

        return [$errors, $warnings];
    }

    /** Variants covered by the promotion's targets (for previews and checks; at most 500). */
    public function coveredSizes(DiscountRule $promotion)
    {
        $included = $promotion->targets->where('is_gift', false)->where('is_excluded', false);
        if ($included->isEmpty()) {
            return collect();
        }

        $ids = fn (string $type) => $included->where('target_type', $type)->pluck('target_id')->all();

        return ProductSize::with(['product' => fn ($q) => $q->basic()])
            ->whereHas('product')
            ->where(function ($q) use ($ids) {
                $q->whereIn('id', $ids('variant') ?: [0])
                    ->orWhereIn('product_id', $ids('product') ?: [0])
                    ->orWhereHas('product', fn ($p) => $p->whereIn('brand_id', $ids('brand') ?: [0])->orWhereIn('category_id', $ids('category') ?: [0]));
            })
            ->orderBy('product_id')->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (ProductSize $size) => $size->product && \App\Modules\Promotions\Engine\TargetMatcher::matches($promotion->targets, $size->product, $size, false))
            ->values();
    }

    private function targetExists(string $type, int $id): bool
    {
        return match ($type) {
            'product' => Product::whereKey($id)->exists(),
            'variant' => ProductSize::whereKey($id)->exists(),
            'brand' => \App\Models\Brand::whereKey($id)->exists(),
            'category' => \App\Models\Category::whereKey($id)->exists(),
            default => false,
        };
    }
}
