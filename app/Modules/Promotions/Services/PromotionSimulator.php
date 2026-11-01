<?php

namespace App\Modules\Promotions\Services;

use App\Models\DiscountRule;
use App\Models\ProductSize;
use App\Models\User;
use App\Modules\Promotions\Engine\PricingInput;
use App\Modules\Promotions\Engine\PricingResult;
use App\Modules\Promotions\Engine\PromotionEngine;
use App\Modules\Promotions\Engine\Strategies\FixedAmountDiscountStrategy;
use App\Modules\Promotions\Engine\Strategies\PercentageDiscountStrategy;
use App\Support\Money;
use App\Support\StoreClock;
use Illuminate\Support\Carbon;

/**
 * The dashboard's promotion preview: runs the real engine on a sample cart with this promotion
 * treated as live (even a draft or scheduled one), at a chosen moment, for an optional customer
 * and coupon. Nothing is written. Also lists the variants the promotion covers with their
 * price before and after, and the stock of its gifts.
 */
class PromotionSimulator
{
    public function __construct(
        private PromotionEngine $engine,
        private PromotionValidator $validator,
        private PercentageDiscountStrategy $percent,
        private FixedAmountDiscountStrategy $fixed,
    ) {
    }

    /**
     * @param  array<int, int>  $lines  product size id => quantity
     */
    public function simulate(DiscountRule $promotion, array $lines, ?User $user = null, ?string $coupon = null, ?float $deliveryFee = null, ?Carbon $at = null): PricingResult
    {
        $at ??= $this->defaultMoment($promotion);
        $input = new PricingInput($user, $coupon, $deliveryFee === null ? null : Money::toMinor($deliveryFee), $at, simulate: $promotion);

        $sizes = ProductSize::with('product')->whereIn('id', array_keys($lines))->get()->keyBy('id');
        foreach ($lines as $sizeId => $quantity) {
            $size = $sizes->get($sizeId);
            if ($size && $size->product && $quantity > 0) {
                $input->addLine("sim-{$sizeId}", $size->product, $size, (int) $quantity);
            }
        }

        return $this->engine->evaluate($input);
    }

    /** Inside the promotion's window: now when it is running, else its start. */
    public function defaultMoment(DiscountRule $promotion): Carbon
    {
        $now = StoreClock::now();
        $starts = $promotion->startsAt();
        $ends = $promotion->endsAt();

        if ($starts && $now->lt($starts)) {
            return $starts->copy();
        }
        if ($ends && $now->gt($ends)) {
            return $starts?->copy() ?? $now;
        }

        return $now;
    }

    /**
     * Covered variants with the price this promotion alone would give (item discounts), and
     * the configuration check.
     *
     * @return array{sizes: list<array>, errors: list<string>, warnings: list<string>, gifts: list<array>}
     */
    public function overview(DiscountRule $promotion): array
    {
        $check = $this->validator->check($promotion);
        $strategy = match ($promotion->discount_type) {
            DiscountRule::TYPE_PERCENT => $this->percent,
            DiscountRule::TYPE_FIXED => $this->fixed,
            default => null,
        };

        $sizes = $this->validator->coveredSizes($promotion)->take(50)->map(function (ProductSize $size) use ($strategy, $promotion) {
            $original = Money::toMinor($size->price);
            $final = $strategy ? max(0, $strategy->apply($promotion, $original)) : $original;
            if ($strategy && $promotion->max_discount_amount !== null) {
                $final = max($final, $original - Money::toMinor($promotion->max_discount_amount));
            }

            return [
                'product' => $size->product?->name_en,
                'size' => $size->size,
                'sku' => $size->sku,
                'stock' => (int) $size->stock,
                'original' => Money::fromMinor($original),
                'final' => Money::fromMinor($final),
                'discount' => Money::fromMinor($original - $final),
            ];
        })->values()->all();

        $gifts = $promotion->rewards->map(fn ($reward) => [
            'label' => $reward->label(),
            'type' => $reward->reward_type,
            'threshold' => $reward->threshold_amount ?? $promotion->min_amount,
            'stock' => $reward->product ? (int) ($reward->size ? $reward->size->stock : $reward->product->sizes->sum('stock')) : null,
        ])->all();

        return ['sizes' => $sizes, 'errors' => $check['errors'], 'warnings' => $check['warnings'], 'gifts' => $gifts];
    }
}
