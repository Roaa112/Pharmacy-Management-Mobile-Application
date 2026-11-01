<?php

namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    // Deleting archives the coupon: orders and redemptions keep pointing at it.
    use SoftDeletes;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'code',
        'name',
        'type',
        'discount_value',
        'min_order_amount',
        'max_discount_amount',
        'is_active',
        'start_at',
        'end_at',
        'used_count',
        'usage_limit',
        'once_per_user',
        'per_user_limit',
        'applies_to_delivery',
        'first_order_only',
        'country_id',
        'combines_with_promotions',
        'customer_scope',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
        'once_per_user' => 'boolean',
        'applies_to_delivery' => 'boolean',
        'first_order_only' => 'boolean',
        'combines_with_promotions' => 'boolean',
    ];

    /** How many times one customer may use it (null = unlimited). once_per_user means 1. */
    public function perUserLimit(): ?int
    {
        if ($this->per_user_limit) {
            return (int) $this->per_user_limit;
        }

        return $this->once_per_user ? 1 : null;
    }

    /** inactive | scheduled | expired | used_up | active, on the store clock. */
    public function state(): string
    {
        $now = StoreClock::now();

        return match (true) {
            !$this->is_active => 'inactive',
            $this->start_at && $this->start_at->gt($now) => 'scheduled',
            $this->end_at && $this->end_at->lt($now) => 'expired',
            $this->usage_limit && $this->used_count >= $this->usage_limit => 'used_up',
            default => 'active',
        };
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function targets()
    {
        return $this->hasMany(CouponTarget::class);
    }

    /** Customers allowed to use it when customer_scope is "specific". */
    public function customers()
    {
        return $this->belongsToMany(User::class, 'coupon_customers');
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
