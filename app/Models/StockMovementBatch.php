<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The batches one stock movement changed (signed). Immutable, like the movement. */
class StockMovementBatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_movement_id', 'inventory_batch_id', 'quantity_change'];

    protected $casts = ['quantity_change' => 'integer'];

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
