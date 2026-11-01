<?php

namespace App\Modules\Promotions\Services;

use App\Models\Address;
use App\Models\Cart;
use App\Models\User;
use App\Modules\Delivery\Services\DeliveryQuote;
use App\Modules\Delivery\Services\DeliveryQuoteService;
use App\Modules\Promotions\Engine\PricingInput;
use App\Modules\Promotions\Engine\PricingResult;
use App\Modules\Promotions\Engine\PromotionEngine;
use App\Support\StoreClock;
use Illuminate\Support\Collection;

/**
 * Builds the engine input from a customer's cart and prices it, delivery included when an
 * address is given. Used by the cart endpoints, the coupon preview, the delivery quote and
 * checkout, so all of them price the same cart the same way.
 *
 * The delivery fee depends on the items subtotal (free-over amounts), and the subtotal after
 * item promotions does not depend on the fee, so the engine runs once to get the subtotal and
 * once more with the fee.
 */
class CartPricingService
{
    public function __construct(private PromotionEngine $engine, private DeliveryQuoteService $delivery)
    {
    }

    /**
     * @param  Collection<int, Cart>  $customerLines  customer-selected lines (product and size loaded)
     * @param  ?Address  $address  null = no delivery (pickup, or delivery not chosen yet)
     * @param  bool  $deliveryChosen  true for pickup (fee 0, known); false for "not chosen yet"
     * @return array{0: PricingResult, 1: ?DeliveryQuote}
     */
    public function price(User $user, Collection $customerLines, ?string $couponCode, ?Address $address, bool $deliveryChosen = false, array $stock = [], bool $forCheckout = false): array
    {
        $build = fn (?int $fee) => $this->input($user, $customerLines, $couponCode, $fee, $stock, $forCheckout);

        if (!$address) {
            return [$this->engine->evaluate($build($deliveryChosen ? 0 : null)), null];
        }

        $itemsOnly = $this->engine->evaluate($build(null));
        $quote = $this->delivery->quote($address, $itemsOnly->subtotal);

        if (!$quote->ok()) {
            return [$itemsOnly, $quote];
        }

        return [$this->engine->evaluate($build($quote->feeMinor)), $quote];
    }

    public function input(User $user, Collection $customerLines, ?string $couponCode, ?int $deliveryFeeMinor, array $stock = [], bool $forCheckout = false): PricingInput
    {
        $input = new PricingInput($user, $couponCode, $deliveryFeeMinor, StoreClock::now(), stock: $stock, forCheckout: $forCheckout);

        foreach ($customerLines as $line) {
            if ($line->product && $line->size && $line->size->product_id === $line->product_id) {
                $input->addLine($line->id, $line->product, $line->size, (int) $line->product_quantity);
            }
        }

        return $input;
    }
}
