<?php
namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A promotion (the table keeps its historical name, discount_rules). Its type decides which
 * strategy of the promotion engine (App\Modules\Promotions) evaluates it:
 *
 *  - percent / fixed       item price discounts for products, variants, brands or categories
 *  - buy_x_get_y           buy N qualifying units, get reward products added as gifts
 *  - amount_gift           spend X (tiers) and get a gift, a discount or free delivery
 *  - free_delivery         free delivery above a qualifying amount
 *
 * Deleting archives it (soft delete), so orders keep pointing at the promotion they used.
 */
class DiscountRule extends Model
{
    use SoftDeletes;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    public const TYPE_SPEND_X_GET_Y = 'amount_gift';
    public const TYPE_FREE_DELIVERY = 'free_delivery';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED, self::TYPE_BUY_X_GET_Y, self::TYPE_SPEND_X_GET_Y, self::TYPE_FREE_DELIVERY];
    public const ITEM_TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];
    public const GIFT_TYPES = [self::TYPE_BUY_X_GET_Y, self::TYPE_SPEND_X_GET_Y];

    public const SCOPE_ALL = 'all';
    public const SCOPE_NEW = 'new';
    public const SCOPE_RETURNING = 'returning';
    public const SCOPE_SPECIFIC = 'specific';

    public const FALLBACK_PARTIAL = 'partial';
    public const FALLBACK_SKIP = 'skip';
    public const FALLBACK_BLOCK = 'block';

    public const TIER_HIGHEST = 'highest';
    public const TIER_CUMULATIVE = 'cumulative';

    protected $fillable = [
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'campaign_id',
        'discount_type',
        'discount_value',
        'min_amount',
        'min_quantity',
        'free_quantity',
        'gift_type',
        'gift_id',
        'gift_quantity',
        'starts_at',
        'ends_at',
        'applies_to_type',
        'applies_to_id',
        'is_active',
        'priority',
        'is_exclusive',
        'is_stackable',
        'repeatable',
        'max_applications_per_order',
        'usage_limit',
        'per_customer_limit',
        'customer_scope',
        'country_id',
        'min_order_amount',
        'max_order_amount',
        'max_discount_amount',
        'qualifying_basis',
        'count_delivery_fee',
        'coupon_reduces_qualifying',
        'tier_mode',
        'gift_fallback',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_exclusive' => 'boolean',
        'is_stackable' => 'boolean',
        'repeatable' => 'boolean',
        'count_delivery_fee' => 'boolean',
        'coupon_reduces_qualifying' => 'boolean',
        'priority' => 'integer',
        'min_quantity' => 'integer',
        'free_quantity' => 'integer',
        'gift_quantity' => 'integer',
    ];

    public function targets()
    {
        return $this->hasMany(DiscountRuleTarget::class, 'discount_rule_id');
    }

    public function rewards()
    {
        return $this->hasMany(PromotionReward::class, 'discount_rule_id')->orderBy('sort_order')->orderBy('id');
    }

    public function campaign()
    {
        return $this->belongsTo(PromotionCampaign::class, 'campaign_id');
    }

    public function customers()
    {
        return $this->belongsToMany(User::class, 'promotion_customers', 'discount_rule_id', 'user_id');
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class, 'discount_rule_id');
    }

    public function orderPromotions()
    {
        return $this->hasMany(OrderPromotion::class, 'discount_rule_id');
    }

    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn('discount_type', (array) $types);
    }

    public function getNameAttribute(): string
    {
        $name = app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name_en) : ($this->name_en ?: $this->name_ar);

        return $name ?: static::typeLabel($this->discount_type).' #'.$this->id;
    }

    public function getDescriptionAttribute(): ?string
    {
        return app()->getLocale() === 'ar' ? ($this->description_ar ?: $this->description_en) : ($this->description_en ?: $this->description_ar);
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            self::TYPE_PERCENT => 'Percentage discount',
            self::TYPE_FIXED => 'Fixed amount discount',
            self::TYPE_BUY_X_GET_Y => 'Buy X get Y',
            self::TYPE_SPEND_X_GET_Y => 'Spend X get Y',
            self::TYPE_FREE_DELIVERY => 'Free delivery',
            default => 'Promotion',
        };
    }

    public function startsAt(): ?Carbon
    {
        return $this->starts_at ? Carbon::parse($this->starts_at) : null;
    }

    public function endsAt(): ?Carbon
    {
        return $this->ends_at ? Carbon::parse($this->ends_at) : null;
    }

    /** inactive | scheduled | active | expired, on the store clock. */
    public function state(?Carbon $now = null): string
    {
        $now ??= StoreClock::now();

        return match (true) {
            !$this->is_active || $this->trashed() => 'inactive',
            !$this->starts_at || !$this->ends_at => 'inactive',
            $this->startsAt()->gt($now) => 'scheduled',
            $this->endsAt()->lt($now) => 'expired',
            default => 'active',
        };
    }
}
