<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_size_id', 'quantity_ordered', 'quantity_received',
        'quantity_damaged', 'quantity_returned', 'unit_cost', 'line_total',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer', 'quantity_received' => 'integer',
        'quantity_damaged' => 'integer', 'quantity_returned' => 'integer',
    ];

    /** Units still expected from the supplier (received includes units that arrived damaged). */
    public function getOutstandingAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
