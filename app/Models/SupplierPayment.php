<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $fillable = ['supplier_id', 'supplier_invoice_id', 'amount', 'paid_on', 'method', 'reference', 'request_key', 'is_advance', 'notes', 'created_by'];

    protected $casts = ['paid_on' => 'date', 'is_advance' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
