<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The stock ledger: one row per change of one bucket of one SKU in one warehouse, with the
 * quantity before and after. Rows are never updated or deleted. Written by InventoryService.
 *
 * Rows from before warehouses existed have warehouse_id null and bucket "sellable" (the old
 * product_sizes.stock) or "quarantine"; the "opening_balance" rows written by the migration
 * start each warehouse balance from those totals.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_INITIAL = 'initial';
    public const TYPE_PURCHASE_RECEIPT = 'purchase_receipt';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_COUNT_CORRECTION = 'count_correction';
    public const TYPE_RESERVATION = 'reservation';
    public const TYPE_RESERVATION_RELEASE = 'reservation_release';
    public const TYPE_FULFILLMENT = 'fulfillment';
    public const TYPE_GIFT = 'gift';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_CANCELLATION_RETURN = 'cancellation_return';
    public const TYPE_SHIPMENT_RETURN = 'shipment_return';
    public const TYPE_RETURN_QUARANTINE = 'return_quarantine';
    public const TYPE_RETURN_RESTOCK = 'return_restock';
    public const TYPE_RETURN_DAMAGED = 'return_damaged';
    public const TYPE_DAMAGE = 'damage';
    public const TYPE_WRITE_OFF = 'write_off';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_TRANSFER_DISPATCH = 'transfer_dispatch';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_LOSS = 'transfer_loss';
    public const TYPE_SUPPLIER_RETURN = 'supplier_return';
    // Batches: units of an expired batch taken off sale; a reservation moved to another batch.
    public const TYPE_EXPIRY = 'expiry';
    public const TYPE_BATCH_REALLOCATION = 'batch_reallocation';
    /** Legacy: stock taken off at checkout before reservations existed. */
    public const TYPE_SALE = 'sale';

    public const TYPES = [
        self::TYPE_OPENING_BALANCE, self::TYPE_INITIAL, self::TYPE_PURCHASE_RECEIPT, self::TYPE_ADJUSTMENT,
        self::TYPE_COUNT_CORRECTION, self::TYPE_RESERVATION, self::TYPE_RESERVATION_RELEASE, self::TYPE_FULFILLMENT,
        self::TYPE_GIFT, self::TYPE_CANCELLATION, self::TYPE_CANCELLATION_RETURN, self::TYPE_SHIPMENT_RETURN,
        self::TYPE_RETURN_QUARANTINE, self::TYPE_RETURN_RESTOCK, self::TYPE_RETURN_DAMAGED, self::TYPE_DAMAGE,
        self::TYPE_WRITE_OFF, self::TYPE_TRANSFER_OUT, self::TYPE_TRANSFER_DISPATCH, self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_LOSS, self::TYPE_SUPPLIER_RETURN, self::TYPE_SALE, self::TYPE_EXPIRY, self::TYPE_BATCH_REALLOCATION,
    ];

    /** Legacy bucket names (rows before warehouses). */
    public const BUCKET_SELLABLE = 'sellable';
    public const BUCKET_QUARANTINE = 'quarantine';

    protected $fillable = [
        'product_id',
        'product_size_id',
        'warehouse_id',
        'type',
        'bucket',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'user_id',
        'admin_id',
        'reason',
        'notes',
    ];

    protected static function booted()
    {
        // The ledger is history: no code path (or admin screen) may rewrite it.
        static::updating(fn () => throw new LogicException('Stock movements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function batchLines()
    {
        return $this->hasMany(StockMovementBatch::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
