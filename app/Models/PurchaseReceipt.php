<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    protected $fillable = ['reference', 'purchase_order_id', 'warehouse_id', 'supplier_invoice_number', 'notes', 'received_by', 'received_at'];

    protected $casts = ['received_at' => 'datetime'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function receiver()
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }
}
