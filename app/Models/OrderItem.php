<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    // Where the line's units are (written only by InventoryService). null = legacy line whose
    // stock was taken off at checkout, before reservations existed.
    public const STOCK_RESERVED = 'reserved';
    public const STOCK_FULFILLED = 'fulfilled';
    public const STOCK_RELEASED = 'released';
    public const STOCK_RESTOCKED = 'restocked';
    public const STOCK_RETURNED = 'returned';

    protected $fillable = [
        'order_id',
        'product_id',
        'product_size_id',
        'quantity',
        'price_at_time',
        'original_price',
        'total_price',
        'is_gift',
        'discount_rule_id',
        'product_name_ar',
        'product_name_en',
        'size_label',
        'promotion_reward_id',
        'flash_sale_id',
        'discount_amount',
        'gift_status',
        'promotion_snapshot',
    ];

    public const GIFT_GRANTED = 'granted';
    public const GIFT_RETURNED = 'returned';

    protected $casts = ['is_gift' => 'boolean', 'promotion_snapshot' => 'array'];

    /** The promotion that set this line's price or gave this gift. */
    public function promotion()
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id')->withTrashed();
    }

    public function flashSale()
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Order history must keep showing the product even after it sells out or is deleted.
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function reservations()
    {
        return $this->hasMany(StockReservation::class);
    }
}
