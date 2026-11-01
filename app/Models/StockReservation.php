<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Units of an order line held in one warehouse until the order is dispatched or canceled. */
class StockReservation extends Model
{
    public const ACTIVE = 'active';
    public const FULFILLED = 'fulfilled';
    public const RELEASED = 'released';

    protected $fillable = ['order_item_id', 'product_size_id', 'warehouse_id', 'quantity', 'status', 'fulfilled_at', 'released_at'];

    protected $casts = ['quantity' => 'integer', 'fulfilled_at' => 'datetime', 'released_at' => 'datetime'];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
