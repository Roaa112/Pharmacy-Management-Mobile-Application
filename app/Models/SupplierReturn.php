<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Goods sent back to a supplier. Stock leaves the warehouse when it is dispatched. */
class SupplierReturn extends Model
{
    public const DRAFT = 'draft';
    public const DISPATCHED = 'dispatched';
    public const CANCELED = 'canceled';

    protected $fillable = [
        'reference', 'supplier_id', 'purchase_order_id', 'warehouse_id', 'status', 'credit_amount',
        'reason', 'created_by', 'dispatched_by', 'dispatched_at',
    ];

    protected $casts = ['dispatched_at' => 'datetime'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(SupplierReturnItem::class);
    }
}
