<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    protected $fillable = ['supplier_id', 'purchase_order_id', 'number', 'invoice_date', 'due_date', 'amount', 'currency_code', 'notes', 'created_by'];

    protected $casts = ['invoice_date' => 'date', 'due_date' => 'date'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
