<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantities of one SKU in one warehouse. Written only by InventoryService, together with the
 * stock_movements rows that explain every change. available = on_hand - reserved.
 */
class InventoryBalance extends Model
{
    public const BUCKETS = ['on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit'];

    protected $fillable = ['warehouse_id', 'product_size_id', 'warehouse_location_id'];

    protected $casts = [
        'on_hand' => 'integer', 'reserved' => 'integer', 'damaged' => 'integer',
        'quarantined' => 'integer', 'in_transit' => 'integer',
    ];

    public function getAvailableAttribute(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
