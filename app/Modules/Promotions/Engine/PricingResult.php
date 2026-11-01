<?php

namespace App\Modules\Promotions\Engine;

use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\ProductSize;
use App\Models\PromotionReward;

/**
 * The engine's answer for one cart: priced lines, gift lines, applied and rejected promotions
 * with reasons, and the totals. Amounts are integer minor units; toArray() formats them.
 *
 * total = subtotal (after item promotions) − promotion discount − coupon discount
 *         + delivery fee − delivery discount, never below zero.
 */
final class PricingResult
{
    /** @var list<PricedLine> */
    public array $lines = [];

    /**
     * @var list<array{gift_key: string, promotion: DiscountRule, reward: PromotionReward, size: ProductSize,
     *     quantity: int, requested_quantity: int, original_unit: int, status: string}>
     */
    public array $gifts = [];

    /** @var list<array> applied promotions (source, id, type, name, code, eligible, discount, delivery_discount, reward_quantity, details, model) */
    public array $applied = [];

    /** @var list<array> rejected promotions (source, id, type, name, reason, message, details) */
    public array $rejected = [];

    /** @var list<array> rewards the customer can still unlock (spend or buy more) */
    public array $hints = [];

    /** @var list<string> reasons checkout must refuse (gift fallback "block") */
    public array $blocking = [];

    public ?Coupon $coupon = null;
    public ?array $couponRejection = null;

    public int $originalSubtotal = 0;
    public int $subtotal = 0;
    public int $promotionDiscount = 0;
    public int $couponDiscount = 0;
    public int $couponItemsDiscount = 0;
    public int $deliveryFee = 0;
    public bool $deliveryKnown = false;
    public int $deliveryDiscount = 0;
    public int $couponDeliveryDiscount = 0;

    public function itemsDiscount(): int
    {
        return $this->originalSubtotal - $this->subtotal;
    }

    public function giftValue(): int
    {
        return array_sum(array_map(fn ($g) => $g['original_unit'] * $g['quantity'], $this->gifts));
    }

    /** Order-level discounts in the order's discount_amount (coupon + automatic + delivery). */
    public function orderDiscount(): int
    {
        return $this->promotionDiscount + $this->couponDiscount + $this->deliveryDiscount;
    }

    public function total(): int
    {
        return max(0, $this->subtotal + $this->deliveryFee - $this->orderDiscount());
    }

    public function totalSavings(): int
    {
        return $this->itemsDiscount() + $this->orderDiscount() + $this->giftValue();
    }

    public function line(string|int $key): ?PricedLine
    {
        foreach ($this->lines as $line) {
            if ((string) $line->key === (string) $key) {
                return $line;
            }
        }

        return null;
    }

    /** Rejections for one promotion (used to explain why a gift went away). */
    public function rejectionFor(string $source, int $id): ?array
    {
        foreach ($this->rejected as $rejection) {
            if ($rejection['source'] === $source && $rejection['id'] === $id) {
                return $rejection;
            }
        }

        return null;
    }

    public function appliedPromotionIds(): array
    {
        return array_values(array_unique(array_map(fn ($a) => $a['id'], array_filter($this->applied, fn ($a) => $a['source'] === 'promotion'))));
    }

    /** Customer/API shape. */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $line) => $line->toArray(), $this->lines),
            'gifts' => array_map(fn ($gift) => $this->giftToArray($gift), $this->gifts),
            'applied_promotions' => array_map(fn ($a) => $this->appliedToArray($a), $this->applied),
            'rejected_promotions' => array_values($this->rejected),
            'next_rewards' => $this->hints,
            'coupon' => $this->coupon ? [
                'code' => $this->coupon->code,
                'discount' => Money::out($this->couponDiscount),
                'delivery_discount' => Money::out($this->couponDeliveryDiscount),
            ] : null,
            'coupon_rejection' => $this->couponRejection,
            'original_subtotal' => Money::out($this->originalSubtotal),
            'subtotal' => Money::out($this->subtotal),
            'items_discount' => Money::out($this->itemsDiscount()),
            'promotion_discount' => Money::out($this->promotionDiscount),
            'coupon_discount' => Money::out($this->couponDiscount),
            'delivery_fee' => Money::out($this->deliveryFee),
            'delivery_discount' => Money::out($this->deliveryDiscount),
            'delivery_included' => $this->deliveryKnown,
            'gift_value' => Money::out($this->giftValue()),
            'total_savings' => Money::out($this->totalSavings()),
            'total' => Money::out($this->total()),
            'blocking' => $this->blocking,
        ];
    }

    public function giftToArray(array $gift): array
    {
        $product = $gift['size']->product;

        return [
            'gift_key' => $gift['gift_key'],
            'promotion_id' => $gift['promotion']->id,
            'promotion_name' => $gift['promotion']->name,
            'promotion_type' => $gift['promotion']->discount_type,
            'reward_id' => $gift['reward']->id,
            'reward_type' => $gift['reward']->reward_type,
            'product_id' => $gift['size']->product_id,
            'product_size_id' => $gift['size']->id,
            'name' => $product?->name,
            'size' => $gift['size']->size,
            'quantity' => $gift['quantity'],
            'requested_quantity' => $gift['requested_quantity'],
            'original_price' => Money::out($gift['original_unit']),
            'price' => 0.0,
            'discount' => Money::out($gift['original_unit'] * $gift['quantity']),
            'status' => $gift['status'],
        ];
    }

    public function appliedToArray(array $applied): array
    {
        return [
            'source' => $applied['source'],
            'id' => $applied['id'],
            'type' => $applied['type'],
            'name' => $applied['name'],
            'code' => $applied['code'] ?? null,
            'eligible_amount' => Money::out($applied['eligible'] ?? 0),
            'discount' => Money::out($applied['discount'] ?? 0),
            'delivery_discount' => Money::out($applied['delivery_discount'] ?? 0),
            'reward_quantity' => $applied['reward_quantity'] ?? 0,
            'details' => $applied['details'] ?? [],
        ];
    }
}
