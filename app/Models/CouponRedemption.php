<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One order's use of a coupon: reserved at checkout (counts toward the limits immediately, under
 * the coupon row lock), consumed when the order completes, released when it is canceled
 * (settings.coupon_release_on_cancel). Refunds after delivery do not give the use back.
 */
class CouponRedemption extends Model
{
    public const RESERVED = 'reserved';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';

    public const COUNTING = [self::RESERVED, self::CONSUMED];

    protected $fillable = ['coupon_id', 'order_id', 'user_id', 'code', 'status', 'discount_amount', 'reserved_at', 'consumed_at', 'released_at'];

    protected $casts = ['reserved_at' => 'datetime', 'consumed_at' => 'datetime', 'released_at' => 'datetime'];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
