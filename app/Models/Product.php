<?php

namespace App\Models;

use App\Modules\Promotions\Engine\PromotionCatalog;
use App\Modules\Promotions\Engine\PromotionEngine;
use App\Support\Money;
use App\Traits\Translatable;
use Illuminate\Support\Facades\Auth;
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



    /**
     * The item promotion that sets this product's catalogue price (first size), from the
     * promotion engine: ['type' => 'flash'|'percent'|'fixed', 'value' => ...] or null.
     * Kept for the appended discount_type attribute and older callers.
     */
    public function getActiveFinalDiscount()
    {
        // Resolved once per model instance: several appended attributes need it.
        if ($this->activeDiscountResolved) {
            return $this->activeDiscount;
        }

        $this->activeDiscountResolved = true;
        $size = $this->sizes->first();

        if (!$size) {
            return $this->activeDiscount = null;
        }

        [, $winner] = app(PromotionEngine::class)->catalogPrice($this, $size, Auth::user());

        return $this->activeDiscount = $winner ? ['type' => $winner['type'], 'value' => $winner['value']] : null;
    }

    /** Forget the cached promotion state, e.g. after a promotion is saved within the same second. */
    public static function flushActiveDiscountCache(): void
    {
        PromotionCatalog::flush();
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
        return $this->sizes
            ->filter(fn($size) => $size->stock > 0)
            ->map(function ($size) {
                return [
                    'id' => $size->id,
                    'size' => $size->size,
                    'original_price' => $size->price,
                    'price' => $this->priceForSize($size),
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
     * Catalogue selling price of one size (flash sale / discount rules, no cart conditions),
     * from the promotion engine. The cart and checkout price whole carts through the same engine.
     */
    public function priceForSize(ProductSize $size)
    {
        [$unit] = app(PromotionEngine::class)->catalogPrice($this, $size, Auth::user());

        return Money::fromMinor($unit);
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

        // The product form links a product to one flash sale (saleable). The engine prices flash
        // sales from flash_sale_items, so the link is mirrored there as a whole-product item.
        static::saved(function (Product $product) {
            if (!$product->wasChanged(['saleable_type', 'saleable_id']) && !$product->wasRecentlyCreated) {
                return;
            }

            FlashSaleItem::where('product_id', $product->id)->where('is_product_link', true)
                ->when($product->saleable_type === FlashSale::class, fn ($q) => $q->where('flash_sale_id', '!=', $product->saleable_id))
                ->delete();

            if ($product->saleable_type === FlashSale::class && $product->saleable_id && FlashSale::whereKey($product->saleable_id)->exists()) {
                FlashSaleItem::firstOrCreate(
                    ['flash_sale_id' => $product->saleable_id, 'product_id' => $product->id, 'product_size_id' => null],
                    ['is_product_link' => true],
                );
            }
        });
    }

}
