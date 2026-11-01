<?php

namespace App\Models;

use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A sellable variant (SKU) of a product: size, colour, SKU code, barcode, price and cost.
 *
 * `stock` and `quarantined_stock` are projections of inventory_balances (sellable units across
 * sellable warehouses / quarantined units everywhere), maintained by InventoryService. They are
 * what the catalogue, cart and checkout read; never write them directly.
 */
class ProductSize extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'size',
        'sku',
        'barcode',
        'color',
        'price',
        'cost_price',
        'stock',
        'min_stock',
        'max_stock',
        'reorder_point',
        'reorder_quantity',
    ];

    protected static function booted()
    {
        // A size created with an opening quantity gets it as an "initial" movement in the default
        // warehouse, so the ledger and the balances always explain the projection.
        static::created(function (ProductSize $size) {
            $opening = (int) $size->stock;

            if ($opening !== 0) {
                static::whereKey($size->id)->update(['stock' => 0]);
                $size->stock = 0;
                static::setStock($size->id, $opening, StockMovement::TYPE_INITIAL, ['reason' => 'Opening stock']);
                $size->refresh();
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function balances()
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function reservations()
    {
        return $this->hasMany(StockReservation::class);
    }

    /** Below the reorder point (or the store's low-stock threshold when none is set). */
    public function needsReorder(int $fallbackThreshold): bool
    {
        return $this->stock <= ($this->reorder_point ?? $fallbackThreshold);
    }

    /**
     * Change the sellable units of the default warehouse by $change (signed), recorded as a
     * movement. Kept for the product form and older callers; warehouse-aware code uses
     * InventoryService directly.
     *
     * $context keys: reference (Model), user_id, admin_id, reason.
     */
    public static function moveStock(int $sizeId, int $change, string $type, array $context = []): ?StockMovement
    {
        $inventory = app(InventoryService::class);

        return $inventory->apply($sizeId, $inventory->defaultWarehouseId(), ['on_hand' => $change], $type, $context, static::movementKey($type, $context))->first();
    }

    /**
     * Set the sellable units (across sellable warehouses) to an absolute value; the difference
     * is applied to the default warehouse.
     */
    public static function setStock(int $sizeId, int $newStock, string $type, array $context = []): ?StockMovement
    {
        $current = (int) static::whereKey($sizeId)->value('stock');

        return static::moveStock($sizeId, $newStock - $current, $type, $context);
    }

    /** Move units into or out of quarantine in the default warehouse (returned medicine, decision D1). */
    public static function moveQuarantine(int $sizeId, int $change, string $type, array $context = []): ?StockMovement
    {
        $inventory = app(InventoryService::class);

        return $inventory->apply($sizeId, $inventory->defaultWarehouseId(), ['quarantined' => $change], $type, $context, static::movementKey($type, $context))->first();
    }

    /** Events with a reference happen once per reference; free-form changes are each unique. */
    private static function movementKey(string $type, array $context): string
    {
        $reference = $context['reference'] ?? null;

        return $reference ? "{$type}:{$reference->getMorphClass()}:{$reference->getKey()}" : "{$type}:".Str::uuid();
    }
}
