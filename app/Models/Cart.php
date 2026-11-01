<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A cart line. Lines the customer chose have is_gift = false. Promotional gifts (is_gift = true)
 * are written only by CartPromotionSync from the promotion engine's result: one line per
 * promotion reward (gift_key), price 0, never editable by the customer.
 */
class Cart extends Model
{
    protected $fillable = [
        'user_id', 'product_id', 'product_quantity', 'price_at_time', 'total_price' ,'product_size_id','original_price',
        'is_gift', 'discount_rule_id', 'promotion_reward_id', 'gift_key',
    ];

    protected $casts = ['is_gift' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function promotion()
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id')->withTrashed();
    }

    public function scopeCustomerLines(Builder $query): Builder
    {
        return $query->where('is_gift', false);
    }

    public function scopeGiftLines(Builder $query): Builder
    {
        return $query->where('is_gift', true);
    }
}
