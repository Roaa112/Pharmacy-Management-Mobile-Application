<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierReturnItem extends Model
{
    protected $fillable = ['supplier_return_id', 'product_size_id', 'purchase_order_item_id', 'source_bucket', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'integer'];

    public function supplierReturn()
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
