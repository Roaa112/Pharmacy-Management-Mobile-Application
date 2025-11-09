<?php

namespace App\Models;

use App\Traits\Translatable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use Translatable;

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
        'final_price', 'discount_type'
    ];

    protected $hidden = ['name_ar', 'name_en', 'description_ar', 'description_en'];

    protected $casts = [
        'rate' => 'float',
    ];



    public function getActiveDiscount()
    {
        $now = now();

        return DB::table('discount_rules')
            ->join('discount_rule_targets', 'discount_rules.id', '=', 'discount_rule_targets.discount_rule_id')
           ->whereNotIn('discount_rules.discount_type', ['buy_x_get_y', 'amount_gift'])

            ->where('discount_rules.starts_at', '<=', $now)
            ->where('discount_rules.ends_at', '>=', $now)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('discount_rule_targets.target_type', 'product')
                        ->where('discount_rule_targets.target_id', $this->id);
                })->orWhere(function ($q2) {
                    $q2->where('discount_rule_targets.target_type', 'brand')
                        ->where('discount_rule_targets.target_id', $this->brand_id);
                })->orWhere(function ($q2) {
                    $q2->where('discount_rule_targets.target_type', 'category')
                        ->where('discount_rule_targets.target_id', $this->category_id);
                });
            })
            ->select('discount_rules.discount_type', 'discount_rules.discount_value')
            ->first();
    }
public function getActiveFinalDiscount()
{
    // Flash sale logic (based only on is_active)
    if (
        $this->saleable_type === FlashSale::class &&
        $this->saleable &&
        $this->saleable->is_active
    ) {
        return [
            'type' => 'flash',
            'value' => $this->saleable->discount_value,
        ];
    }

    // Discount rule logic
    $discount = $this->getActiveDiscount();
    if ($discount) {
        return [
            'type' => $discount->discount_type,
            'value' => $discount->discount_value,
        ];
    }

    return null;
}

public function getFinalPriceAfterDiscount(Product $product, ProductSize $size)
{
    $originalPrice = $size->price;
    $finalPrice = $originalPrice;

    // لو عندك خصومات في جدول discount_rules
    $rule = DiscountRule::where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->where(function($query) use ($product) {
            $query->where('brand_id', $product->brand_id)
                  ->orWhere('category_id', $product->category_id);
        })->orderByDesc('discount_value')->first();

    if ($rule) {
        if ($rule->discount_type === 'percent') {
            $finalPrice = $originalPrice * (1 - $rule->discount_value / 100);
        } elseif ($rule->discount_type === 'fixed') {
            $finalPrice = max(0, $originalPrice - $rule->discount_value);
        }
    }

    return round($finalPrice, 2);
}

    // public function getPriceAfterDiscountAttribute()
    // {
    //     $firstSize = $this->sizes()->first();
    //     if (!$firstSize) return null;

    //     $price = $firstSize->price;
    //     $discount = $this->getActiveFinalDiscount();

    //     if ($discount) {
    //         if ($discount['type'] === 'fixed' || $discount['type'] === 'flash') {
    //             return max(0, $price - $discount['value']);
    //         } elseif ($discount['type'] === 'percent') {
    //             return round($price * (1 - $discount['value'] / 100), 2);
    //         }
    //     }

    //     return $price;
    // }
   public function getFinalPriceAttribute()
{
    $firstSize = $this->sizes()->first();
    if (!$firstSize) return 0;

    $price = $firstSize->price;

    // ✅ 1. لو المنتج عليه فلاش سيل Active → نطبق فقط الفلاش
    if (
        $this->saleable_type === FlashSale::class &&
        $this->saleable &&
        $this->saleable->is_active
    ) {
        $start = Carbon::parse($this->saleable->date . ' ' . $this->saleable->time);
        $end = $start->copy()->addHour();

        if (now()->between($start, $end)) {
            return round($price * (1 - ($this->saleable->discount_value / 100)), 2);
        }
    }

    // ✅ 2. لو مفيش فلاش سيل active → نطبق الخصومات العادية (discount_rules)
    $discount = $this->getActiveDiscount();

    if ($discount) {
        if ($discount->discount_type === 'fixed') {
            return max(0, $price - $discount->discount_value);
        } elseif ($discount->discount_type === 'percent') {
            return round($price * (1 - $discount->discount_value / 100), 2);
        }
    }

    // ✅ 3. لو مفيش أي خصم → السعر الأصلي
    return $price;
}

public function getAvailableSizesAttribute()
{
    $discount = $this->getActiveFinalDiscount();

    return $this->sizes
        ->filter(fn($size) => $size->stock > 0)
        ->map(function ($size) use ($discount) {
            $originalPrice = $size->price;
            $finalPrice = $originalPrice;

            if ($discount) {
                if (in_array($discount['type'], ['fixed'])) {
                    // خصم ثابت أو خصم فلاش (قيمة ثابتة)
                    $finalPrice = max(0, $originalPrice - $discount['value']);
                } elseif (in_array($discount['type'], ['percent', 'flash'])) {
                    // خصم نسبي (نسبة مئوية)
                    $finalPrice = round($originalPrice * (1 - $discount['value'] / 100), 2);
                }
            }

            return [
                'id' => $size->id,
                'size' => $size->size,
                'original_price' => $originalPrice,
                'price' => $finalPrice,
                'stock' => $size->stock,
            ];
        })->values();
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
        return $this->reviews()->count();
    }

    public function getAverageRatingAttribute()
    {
        return round($this->reviews()->avg('rate'), 1) ?? 0;
    }

    public function getIsFavoriteAttribute()
    {
        $user = Auth::user();
        return $user ? $this->favorites()->where('user_id', $user->id)->exists() : false;
    }

    // ====================== Scope ======================

    protected static function boot()
    {
        parent::boot();

        if (!app()->runningInConsole() && !request()->is('dashboard*')) {
            static::addGlobalScope('available', function (Builder $builder) {
                $builder->whereHas('sizes', fn($q) => $q->where('stock', '>', 0));
            });
        }
    }

}
