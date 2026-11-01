<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable snapshot of a promotion, flash sale or coupon applied to an order: its name, type,
 * code, eligible amount, discount and rewards as they were at checkout. Editing or deleting
 * the promotion later never changes it.
 */
class OrderPromotion extends Model
{
    public const SOURCE_PROMOTION = 'promotion';
    public const SOURCE_FLASH_SALE = 'flash_sale';
    public const SOURCE_COUPON = 'coupon';

    protected $fillable = ['order_id', 'source', 'discount_rule_id', 'flash_sale_id', 'coupon_id', 'promotion_type', 'name', 'code',
        'eligible_amount', 'discount_amount', 'delivery_discount_amount', 'reward_quantity', 'details'];

    protected $casts = ['details' => 'array'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Order promotion snapshots cannot be changed.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function promotion()
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id')->withTrashed();
    }
}
