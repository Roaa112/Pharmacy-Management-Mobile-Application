<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'code', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number',
        'currency_code', 'payment_terms_days', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'payment_terms_days' => 'integer'];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function returns()
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * What we owe the supplier: invoices - payments - credit from dispatched returns.
     * Positive = payable, negative = the supplier owes us.
     */
    public function balanceMinor(): int
    {
        $invoiced = Money::toMinor($this->invoices()->sum('amount'));
        $paid = Money::toMinor($this->payments()->sum('amount'));
        $credited = Money::toMinor($this->returns()->where('status', SupplierReturn::DISPATCHED)->sum('credit_amount'));

        return $invoiced - $paid - $credited;
    }
}
