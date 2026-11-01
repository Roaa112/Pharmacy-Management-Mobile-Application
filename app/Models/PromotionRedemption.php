<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One order's use of a promotion. Reserved at checkout (counts toward the limits), consumed
 * when the order completes, released when it is canceled. Written by PromotionLifecycle only.
 */
class PromotionRedemption extends Model
{
    public const RESERVED = 'reserved';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';

    /** Statuses that count toward usage limits. */
    public const COUNTING = [self::RESERVED, self::CONSUMED];

    protected $fillable = ['discount_rule_id', 'order_id', 'user_id', 'status', 'discount_amount', 'reward_quantity', 'consumed_at', 'released_at'];

    protected $casts = ['consumed_at' => 'datetime', 'released_at' => 'datetime'];

    public function promotion()
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id')->withTrashed();
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
