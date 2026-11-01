<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An order to a supplier. Creating or approving it never changes stock; only receipts
 * (PurchasingService::receive) put units into a warehouse.
 */
class PurchaseOrder extends Model
{
    public const DRAFT = 'draft';
    public const ORDERED = 'ordered';
    public const PARTIALLY_RECEIVED = 'partially_received';
    public const RECEIVED = 'received';
    public const CLOSED = 'closed';
    public const CANCELED = 'canceled';

    public const STATUSES = [self::DRAFT, self::ORDERED, self::PARTIALLY_RECEIVED, self::RECEIVED, self::CLOSED, self::CANCELED];

    protected $fillable = [
        'reference', 'supplier_id', 'warehouse_id', 'status', 'currency_code', 'order_date', 'expected_at',
        'subtotal', 'tax_amount', 'shipping_cost', 'total', 'notes', 'created_by', 'approved_by',
        'ordered_at', 'closed_at', 'canceled_at',
    ];

    protected $casts = [
        'order_date' => 'date', 'expected_at' => 'date',
        'ordered_at' => 'datetime', 'closed_at' => 'datetime', 'canceled_at' => 'datetime',
    ];

    public function canReceive(): bool
    {
        return in_array($this->status, [self::ORDERED, self::PARTIALLY_RECEIVED], true);
    }

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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseReceipt::class)->latest('received_at');
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
