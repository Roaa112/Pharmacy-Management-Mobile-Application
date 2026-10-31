<?php

namespace App\Models;

use App\Support\Money;
use App\Traits\Translatable;
use App\Support\StoreClock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    // Deleted products stay in the database so order history keeps pointing at them.
    use Translatable, SoftDeletes;

    // Every serialized product reads its sizes and promotion; loading them up front avoids N+1 queries.
    protected $with = ['sizes', 'saleable'];

    private bool $activeDiscountResolved = false;
    private ?array $activeDiscount = null;

    /** Active discount rule targets, cached per store-clock second (see activeDiscountTargets). */
    private static array $activeDiscountCache = [];

    protected $fillable = [
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'rate',
        'image',
        'brand_id',
        'category_id',
        'saleable_id',
        'saleable_type',
    ];

    protected $appends = [
        'name', 'description', 'is_favorite', 'image_url', 'images',
        'average_rating', 'ratings_count', 'available_sizes',
        'final_price', 'discount_type',
        // Added for decision D6 (sold-out products stay visible). available_sizes keeps its
        // meaning (sizes that can be bought) so older app versions behave as before.
        'in_stock', 'stock_status', 'size_options',
    ];

    protected $hidden = [
        'name_ar', 'name_en', 'description_ar', 'description_en', 'deleted_at',
        // Aggregates loaded by the "listing_aggregates" scope; exposed through the appended attributes.
        'reviews_count', 'reviews_avg_rate', 'favorited_by_current_user',
    ];

    protected $casts = [
        'rate' => 'float',
    ];



    public function getActiveDiscount()
    {
        $targets = [
            'product' => $this->id,
            'brand' => $this->brand_id,
            'category' => $this->category_id,
        ];

        return static::activeDiscountTargets()->first(
            fn ($row) => isset($targets[$row->target_type]) && $targets[$row->target_type] == $row->target_id
        );
    }

    /**
     * Active price discount rules with their targets, loaded once per request
     * instead of one query per product.
     */
    protected static function activeDiscountTargets()
    {
        // Cached per store-clock second (not for the whole process), so a rule that starts
        // or ends is picked up immediately and every price in one request uses the same rules.
        $now = StoreClock::now()->toDateTimeString();
        $cache = &static::$activeDiscountCache;

        if (($cache['at'] ?? null) !== $now) {
            $cache = ['at' => $now, 'rows' => DB::table('discount_rules')
                ->join('discount_rule_targets', 'discount_rules.id', '=', 'discount_rule_targets.discount_rule_id')
                ->whereNotIn('discount_rules.discount_type', ['buy_x_get_y', 'amount_gift'])
                ->where('discount_rules.starts_at', '<=', $now)
                ->where('discount_rules.ends_at', '>=', $now)
                // Several matching rules: the first one wins, consistently (oldest rule first).
                ->orderBy('discount_rules.id')
                ->select('discount_rules.discount_type', 'discount_rules.discount_value', 'discount_rule_targets.target_type', 'discount_rule_targets.target_id')
                ->get()];
        }

        return $cache['rows'];
    }

    /** Forget the cached rules, e.g. after a rule is saved within the same second. */
    public static function flushActiveDiscountCache(): void
    {
        static::$activeDiscountCache = [];
    }

/**
 * The one promotion that sets this product's price. No stacking: a live flash sale wins;
 * otherwise the first active discount rule for the product, its brand or its category.
 * Coupons are applied later, to the order total, at checkout.
 */
public function getActiveFinalDiscount()
{
    // Resolved once per model instance: several appended attributes need it.
    if ($this->activeDiscountResolved) {
        return $this->activeDiscount;
    }

    $this->activeDiscountResolved = true;

    // Flash sale: only inside its time window (decision D7).
    if (
        $this->saleable_type === FlashSale::class &&
        $this->saleable instanceof FlashSale &&
        $this->saleable->isLive()
    ) {
        return $this->activeDiscount = [
            'type' => 'flash',
            'value' => $this->saleable->discount_value,
        ];
    }

    // Discount rule logic
    $discount = $this->getActiveDiscount();
    if ($discount) {
        return $this->activeDiscount = [
            'type' => $discount->discount_type,
            'value' => $discount->discount_value,
        ];
    }

    return $this->activeDiscount = null;
}

/**
 * Display price: the first size after the active promotion, the same calculation the cart
 * and checkout use (priceForSize).
 */
public function getFinalPriceAttribute()
{
    $firstSize = $this->sizes->first();
    if (!$firstSize) return 0;

    return $this->priceForSize($firstSize);
}
public function getAvailableSizesAttribute()
{
    $discount = $this->getActiveFinalDiscount();

    return $this->sizes
        ->filter(fn($size) => $size->stock > 0)
        ->map(function ($size) use ($discount) {
            return [
                'id' => $size->id,
                'size' => $size->size,
                'original_price' => $size->price,
                'price' => $this->applyDiscount($size->price, $discount),
                'stock' => $size->stock,
            ];
        })->values();
}

/**
 * Every size, sold out or not, so the app can show sold-out sizes as disabled (D6).
 */
public function getSizeOptionsAttribute()
{
    return $this->sizes->map(fn ($size) => [
        'id' => $size->id,
        'size' => $size->size,
        'original_price' => $size->price,
        'price' => $this->priceForSize($size),
        'stock' => max(0, (int) $size->stock),
        'in_stock' => $size->stock > 0,
    ])->values();
}

public function getInStockAttribute(): bool
{
    return $this->sizes->contains(fn ($size) => $size->stock > 0);
}

public function getStockStatusAttribute(): string
{
    return $this->in_stock ? 'in_stock' : 'out_of_stock';
}

/**
 * Authoritative selling price of one of this product's sizes (after flash sale / discount rules).
 * Used by the cart and checkout so the client never decides the price.
 */
public function priceForSize(ProductSize $size)
{
    return $this->applyDiscount($size->price, $this->getActiveFinalDiscount());
}

private function applyDiscount($originalPrice, ?array $discount)
{
    if (!$discount) {
        return $originalPrice;
    }

    $price = Money::toMinor($originalPrice);

    if (in_array($discount['type'], ['fixed'])) {
        // خصم ثابت (قيمة ثابتة)
        return Money::fromMinor(max(0, $price - Money::toMinor($discount['value'])));
    }

    if (in_array($discount['type'], ['percent', 'flash'])) {
        // خصم نسبي (نسبة مئوية)
        return Money::fromMinor(Money::applyPercent($price, $discount['value']));
    }

    return $originalPrice;
}


    public function getDiscountTypeAttribute()
    {
        $discount = $this->getActiveFinalDiscount();
        return $discount['type'] ?? null;
    }

    // ====================== Relationships ======================

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function healthIssues(): BelongsToMany
    {
        return $this->belongsToMany(HealthIssue::class, 'health_issue_product', 'product_id', 'health_issue_id');
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function saleable(): MorphTo
    {
        return $this->morphTo();
    }


    public function getImageUrlAttribute()
    {
        return $this->image ? asset($this->image) : null;
    }

    public function getImagesAttribute()
    {
        $images = $this->relationLoaded('productImages') ? $this->productImages : $this->productImages()->get();

        return $images->map(fn($image) => $image->image_url)->filter();
    }

    public function getNameAttribute()
    {
        return $this->getTranslatedAttribute('name');
    }

    public function getDescriptionAttribute()
    {
        return $this->getTranslatedAttribute('description');
    }

    public function getRatingsCountAttribute()
    {
        return (int) ($this->attributes['reviews_count'] ?? $this->reviews()->count());
    }

    public function getAverageRatingAttribute()
    {
        $average = array_key_exists('reviews_avg_rate', $this->attributes)
            ? $this->attributes['reviews_avg_rate']
            : $this->reviews()->avg('rate');

        return round((float) $average, 1);
    }

    public function getIsFavoriteAttribute()
    {
        if (array_key_exists('favorited_by_current_user', $this->attributes)) {
            return (bool) $this->attributes['favorited_by_current_user'];
        }

        $user = Auth::user();
        return $user ? $this->favorites()->where('user_id', $user->id)->exists() : false;
    }

    // ====================== Scope ======================

    /**
     * Just the product row: no default eager loads or rating aggregates.
     * For admin lists that only need the name or image.
     */
    public function scopeBasic(Builder $query): Builder
    {
        return $query->without(['sizes', 'saleable'])->withoutGlobalScope('listing_aggregates');
    }

    protected static function booted()
    {
        // Sold-out products stay visible in the app with stock_status "out_of_stock" (decision D6);
        // they used to be hidden by an "available" scope. Deleted products are hidden by SoftDeletes.

        // Rating and favourite data in the same query instead of three extra queries per product.
        static::addGlobalScope('listing_aggregates', function (Builder $builder) {
            $builder->withCount('reviews')->withAvg('reviews', 'rate');

            if ($userId = Auth::id()) {
                $builder->withExists(['favorites as favorited_by_current_user' => fn ($q) => $q->where('user_id', $userId)]);
            }
        });
    }

}
