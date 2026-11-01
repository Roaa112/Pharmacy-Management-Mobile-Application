<?php

namespace App\Modules\Promotions\Engine\Strategies;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Modules\Promotions\Engine\EligibilityChecker;
use App\Modules\Promotions\Engine\PricedLine;
use App\Modules\Promotions\Engine\PricingInput;
use App\Support\Money;

/**
 * Flash sale price for a line: the sale must be live (catalogue already filtered by the store
 * clock), cover the product or the variant, and have units left at the flash price overall,
 * for the item and for this customer. A line that would go over a limit does not get the flash
 * price at all (the reason says how many units are left), so one line never mixes two prices.
 */
class FlashSaleStrategy
{
    public function __construct(private EligibilityChecker $eligibility)
    {
    }

    /** @return ItemCandidate|array{0: string, 1: array}|null candidate, rejection [reason, params], or null when not covered */
    public function candidate(FlashSale $sale, PricedLine $line, PricingInput $input): ItemCandidate|array|null
    {
        $item = $this->itemFor($sale, $line);
        if (!$item) {
            return null;
        }

        if ($reason = $this->eligibility->flashSale($sale, $input)) {
            return $reason;
        }

        foreach ($this->limits($sale, $item, $line, $input) as [$limit, $sold, $reasonWhenOver]) {
            $left = $limit - $sold;
            if ($left <= 0) {
                return [$reasonWhenOver === 'flash_customer_limit' ? 'flash_customer_limit' : 'flash_sale_sold_out', ['limit' => $limit, 'available' => 0]];
            }
            if (!$input->catalogOnly && $line->quantity > $left) {
                return [$reasonWhenOver, ['limit' => $limit, 'available' => $left]];
            }
        }

        return new ItemCandidate(
            source: 'flash_sale',
            id: $sale->id,
            type: 'flash',
            name: $sale->name,
            rank: 1,
            priority: (int) $sale->priority,
            specificity: $item->product_size_id ? config('promotions.specificity.variant') : config('promotions.specificity.product'),
            stackable: false,
            apply: fn (int $unit) => static::discounted($sale, $unit),
            model: $sale,
        );
    }

    public static function discounted(FlashSale $sale, int $unit): int
    {
        return ($sale->discount_type ?? FlashSale::TYPE_PERCENT) === FlashSale::TYPE_FIXED
            ? max(0, $unit - Money::toMinor($sale->discount_value))
            : Money::applyPercent($unit, $sale->discount_value);
    }

    /** The variant entry wins over a whole-product entry of the same sale. */
    private function itemFor(FlashSale $sale, PricedLine $line): ?FlashSaleItem
    {
        $items = $sale->items->where('product_id', $line->product->id);

        return $items->firstWhere('product_size_id', $line->size->id) ?? $items->first(fn ($i) => $i->product_size_id === null);
    }

    /** @return list<array{0: int, 1: int, 2: string}> [limit, already sold, reason] */
    private function limits(FlashSale $sale, FlashSaleItem $item, PricedLine $line, PricingInput $input): array
    {
        $limits = [];

        if ($sale->quantity_limit) {
            $limits[] = [$sale->quantity_limit, $this->eligibility->flashSold($sale, $input), 'flash_quantity_exceeded'];
        }
        if ($item->quantity_limit) {
            $limits[] = [$item->quantity_limit, $this->eligibility->flashSold($sale, $input, null, $item->product_id, $item->product_size_id), 'flash_quantity_exceeded'];
        }
        if ($sale->per_customer_limit && $input->user) {
            $limits[] = [$sale->per_customer_limit, $this->eligibility->flashSold($sale, $input, $input->user->id), 'flash_customer_limit'];
        }

        return $limits;
    }
}
