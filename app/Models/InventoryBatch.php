<?php

namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One lot of one SKU: lot number and expiry date as printed on the pack. The legacy batch of a
 * SKU holds the units that existed before batches were tracked (lot and expiry unknown).
 *
 * Sellable = not expired and not inside the minimum shelf life (pharmacy.min_shelf_life_days);
 * a batch without an expiry date (legacy, or a product without one) stays sellable.
 */
class InventoryBatch extends Model
{
    protected $fillable = ['product_size_id', 'lot_number', 'expiry_date', 'is_legacy', 'supplier_id', 'purchase_receipt_item_id', 'unit_cost', 'notes'];

    protected $casts = ['expiry_date' => 'date', 'is_legacy' => 'boolean'];

    /** The last day a batch must still be good for to be sold today. */
    public static function sellableCutoff(): string
    {
        return StoreClock::now()->startOfDay()->addDays(max(0, (int) config('pharmacy.min_shelf_life_days')))->toDateString();
    }

    public function isSellable(): bool
    {
        return $this->expiry_date === null || $this->expiry_date->toDateString() > self::sellableCutoff();
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->toDateString() <= StoreClock::now()->toDateString();
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', self::sellableCutoff()));
    }

    /**
     * The order units leave in:
     *  1. the legacy batch: stock from before batch tracking (and later unbatched additions). Its
     *     expiry and exact age are unknown, but it is at least as old as anything tracked, so it
     *     goes first rather than ageing on the shelf behind newer dated lots;
     *  2. dated lots, earliest expiry first (FEFO);
     *  3. lots received without an expiry date, oldest receipt first.
     * No expiry is ever invented for unknown stock: it stays sellable and is reported as
     * "expiry unknown" until a stock count records its lots.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN inventory_batches.is_legacy = 1 THEN 0 WHEN inventory_batches.expiry_date IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('inventory_batches.expiry_date')->orderBy('inventory_batches.id');
    }

    public function label(): string
    {
        if ($this->is_legacy) {
            return 'Before batch tracking (expiry unknown)';
        }

        return trim(($this->lot_number ?: 'No lot').($this->expiry_date ? ' · exp '.$this->expiry_date->toDateString() : ' · received '.$this->created_at?->toDateString().', expiry unknown'));
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function balances()
    {
        return $this->hasMany(BatchBalance::class);
    }
}
