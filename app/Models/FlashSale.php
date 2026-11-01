<?php

namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A time-boxed price for chosen products or variants (flash_sale_items), percent or fixed,
 * with optional unit limits overall and per customer. Priced by the promotion engine
 * (FlashSaleStrategy); a live flash sale wins over regular discounts (decision D7).
 */
class FlashSale extends Model
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'name_en',
        'name_ar',
        'discount_type',
        'discount_value',
        'is_active',
        'date',
        'time',
        'ends_at',
        'priority',
        'quantity_limit',
        'per_customer_limit',
        'country_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'quantity_limit' => 'integer',
        'per_customer_limit' => 'integer',
    ];

    protected $appends = ['starts_at', 'ends_at'];

    protected static function booted()
    {
        // Every sale has an explicit end. Callers that still create one without it (older
        // integrations) get the configured default length, written down so it never changes.
        static::saving(function (FlashSale $sale) {
            if (empty($sale->attributes['ends_at']) && $sale->date && $sale->time) {
                $sale->attributes['ends_at'] = $sale->starts_at->copy()->addMinutes((int) config('promotions.flash_sale_default_minutes', 60))->toDateTimeString();
            }
        });
    }

    /**
     * Start as the store's wall-clock time (see StoreClock).
     */
    public function getStartsAtAttribute()
    {
        return Carbon::parse($this->date)->setTimeFromTimeString((string) $this->time);
    }

    /** Store wall-clock end. */
    public function getEndsAtAttribute()
    {
        $saved = $this->attributes['ends_at'] ?? null;

        return $saved
            ? Carbon::parse($saved)
            : $this->starts_at->copy()->addMinutes((int) config('promotions.flash_sale_default_minutes', 60));
    }

    public function getNameAttribute(): string
    {
        $name = app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name_en) : ($this->name_en ?: $this->name_ar);

        return $name ?: 'Flash sale #'.$this->id;
    }

    /**
     * The only rule for whether the flash sale price applies: enabled, and the store clock
     * is inside [starts_at, ends_at) — live exactly at the start, over exactly at the end.
     */
    public function isLive(?Carbon $at = null): bool
    {
        if (!$this->is_active || !$this->date || !$this->time) {
            return false;
        }

        $at ??= StoreClock::now();

        return $at->gte($this->starts_at) && $at->lt($this->ends_at);
    }

    public function state(?Carbon $at = null): string
    {
        $at ??= StoreClock::now();

        return match (true) {
            !$this->is_active => 'inactive',
            $at->lt($this->starts_at) => 'scheduled',
            $at->gte($this->ends_at) => 'expired',
            default => 'active',
        };
    }

    public function items()
    {
        return $this->hasMany(FlashSaleItem::class);
    }

    /** The product linked from the product form (legacy single link). */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'saleable');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
