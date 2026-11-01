<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    protected $fillable = ['stock_transfer_id', 'product_size_id', 'quantity_requested', 'quantity_dispatched', 'quantity_received', 'quantity_lost'];

    protected $casts = [
        'quantity_requested' => 'integer', 'quantity_dispatched' => 'integer',
        'quantity_received' => 'integer', 'quantity_lost' => 'integer',
    ];

    /** Dispatched units neither received nor written off yet. */
    public function getInTransitAttribute(): int
    {
        return max(0, $this->quantity_dispatched - $this->quantity_received - $this->quantity_lost);
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
