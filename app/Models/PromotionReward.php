<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a gift promotion gives. Buy X get Y rewards are free products; Spend X get Y rewards
 * can be a free product, a fixed or percentage discount, or free delivery, each from its
 * own threshold (tiers).
 */
class PromotionReward extends Model
{
    public const FREE_PRODUCT = 'free_product';
    public const FIXED_DISCOUNT = 'fixed_discount';
    public const PERCENT_DISCOUNT = 'percent_discount';
    public const FREE_DELIVERY = 'free_delivery';

    public const TYPES = [self::FREE_PRODUCT, self::FIXED_DISCOUNT, self::PERCENT_DISCOUNT, self::FREE_DELIVERY];

    protected $fillable = ['discount_rule_id', 'threshold_amount', 'reward_type', 'product_id', 'product_size_id', 'quantity', 'value', 'max_discount_amount', 'sort_order'];

    protected $casts = ['quantity' => 'integer', 'sort_order' => 'integer'];

    public function promotion()
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function label(): string
    {
        return match ($this->reward_type) {
            self::FREE_PRODUCT => trim(($this->quantity > 1 ? $this->quantity.' × ' : '').($this->product?->name_en ?? 'Product #'.$this->product_id).($this->size ? ' ('.$this->size->size.')' : '')),
            self::FIXED_DISCOUNT => rtrim(rtrim((string) $this->value, '0'), '.').' '.config('pharmacy.currency', 'EGP').' off',
            self::PERCENT_DISCOUNT => rtrim(rtrim((string) $this->value, '0'), '.').'% off',
            self::FREE_DELIVERY => 'Free delivery',
            default => (string) $this->reward_type,
        };
    }
}
