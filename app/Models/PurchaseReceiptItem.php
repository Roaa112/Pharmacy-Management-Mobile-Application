<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    protected $fillable = ['purchase_receipt_id', 'purchase_order_item_id', 'product_size_id', 'quantity_received', 'quantity_damaged', 'unit_cost'];

    protected $casts = ['quantity_received' => 'integer', 'quantity_damaged' => 'integer'];

    public function receipt()
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
