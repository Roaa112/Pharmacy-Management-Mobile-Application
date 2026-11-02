<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Units of one batch in one warehouse, per bucket (same buckets as InventoryBalance). Written by InventoryService only. */
class BatchBalance extends Model
{
    protected $fillable = ['warehouse_id', 'inventory_batch_id', 'product_size_id'];

    protected $casts = ['on_hand' => 'integer', 'reserved' => 'integer', 'damaged' => 'integer', 'quarantined' => 'integer', 'in_transit' => 'integer'];

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
