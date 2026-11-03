<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something customers can get for points. The type decides how it is handed over:
 *  - product: a size of a catalog product, taken out of stock when the redemption is fulfilled;
 *  - free_delivery / voucher / custom: handed over by staff (the reward stock limits how many).
 * New reward types only need a type value and their fulfilment step (RewardRedemptionService).
 *
 * stock is the reward's own allowance (null = unlimited); redeemed_count counts the units of
 * redemptions that were not cancelled or expired. Deleting keeps the row (soft delete) for history.
 */
class LoyaltyReward extends Model
{
    use SoftDeletes;

    public const TYPE_PRODUCT = 'product';
    public const TYPE_FREE_DELIVERY = 'free_delivery';
    public const TYPE_VOUCHER = 'voucher';
    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [self::TYPE_PRODUCT, self::TYPE_FREE_DELIVERY, self::TYPE_VOUCHER, self::TYPE_CUSTOM];

    protected $fillable = [
        'code', 'type', 'product_id', 'product_size_id', 'title', 'description', 'image', 'points_required', 'stock',
        'is_active', 'starts_at', 'ends_at', 'per_customer_limit', 'total_limit', 'terms', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'points_required' => 'integer',
        'stock' => 'integer',
        'redeemed_count' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'per_customer_limit' => 'integer',
        'total_limit' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }

    /** Active, inside its dates. (Stock and limits are checked when redeeming, under a lock.) */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isAvailableNow(): bool
    {
        return $this->is_active && !$this->trashed()
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()));
    }

    public function inStock(int $quantity = 1): bool
    {
        return $this->stock === null || $this->stock >= $quantity;
    }

    public function imageUrl(): ?string
    {
        if (!$this->image) {
            return $this->product?->image_url;
        }

        // Paths are relative to public/ like every image column (MarketingImage::store).
        return str_starts_with($this->image, 'http') ? $this->image : asset($this->image);
    }

    public function statusLabel(): string
    {
        return match (true) {
            !$this->is_active => 'inactive',
            $this->starts_at && $this->starts_at->gt(now()) => 'scheduled',
            $this->ends_at && $this->ends_at->lte(now()) => 'expired',
            !$this->inStock() => 'used_up',
            default => 'active',
        };
    }
}
