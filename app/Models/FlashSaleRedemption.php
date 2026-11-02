<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Units of one order line sold at a flash sale's price. Reserved at checkout (counts toward the
 * sale's limits), consumed when the order completes, released when it is canceled. Written by
 * PromotionLifecycle only.
 */
class FlashSaleRedemption extends Model
{
    public const RESERVED = 'reserved';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';

    /** Statuses that count toward the sale's limits. */
    public const COUNTING = [self::RESERVED, self::CONSUMED];

    protected $fillable = ['flash_sale_id', 'order_id', 'order_item_id', 'user_id', 'product_id', 'product_size_id', 'quantity', 'status', 'consumed_at', 'released_at'];

    protected $casts = ['quantity' => 'integer', 'consumed_at' => 'datetime', 'released_at' => 'datetime'];

    public function flashSale()
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
