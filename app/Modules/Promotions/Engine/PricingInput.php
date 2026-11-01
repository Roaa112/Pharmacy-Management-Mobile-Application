<?php

namespace App\Modules\Promotions\Engine;

use App\Models\Country;
use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything the engine prices: the customer-selected lines (never gift lines: gifts are an
 * output), the customer, the coupon code, the delivery fee and the clock.
 *
 * Amounts come only from the database (sizes' prices); nothing the app sends is used as a price.
 */
final class PricingInput
{
    /** @var list<array{key: string|int, product: Product, size: ProductSize, quantity: int}> */
    public array $lines = [];

    /**
     * @param  ?int  $deliveryFeeMinor  null = delivery not chosen yet (cart without address)
     * @param  array<int, int>  $stock  size id => sellable units, when the caller holds row locks
     * @param  bool  $forCheckout  lock limited promotions/flash sales/coupon and count usage live
     * @param  ?DiscountRule  $simulate  treat this promotion as live (dashboard preview)
     */
    public function __construct(
        public ?User $user,
        public ?string $couponCode,
        public ?int $deliveryFeeMinor,
        public Carbon $now,
        public ?Country $country = null,
        public array $stock = [],
        public bool $forCheckout = false,
        public ?DiscountRule $simulate = null,
        public bool $catalogOnly = false,
    ) {
        $this->country ??= Country::default();
    }

    public function addLine(string|int $key, Product $product, ProductSize $size, int $quantity): self
    {
        $this->lines[] = ['key' => $key, 'product' => $product, 'size' => $size, 'quantity' => max(0, $quantity)];

        return $this;
    }

    public function currency(): string
    {
        return $this->country?->currency_code ?? config('pharmacy.currency', 'EGP');
    }

    public function stockOf(ProductSize $size): int
    {
        return max(0, (int) ($this->stock[$size->id] ?? $size->stock));
    }
}
