<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse-to-warehouse transfer: requested -> approved -> in_transit -> (partially_)received
 * -> closed. Dispatch takes units off the source and records them in_transit at the destination;
 * receiving moves them to the destination's on_hand; closing writes off what never arrived.
 */
class StockTransfer extends Model
{
    public const REQUESTED = 'requested';
    public const APPROVED = 'approved';
    public const IN_TRANSIT = 'in_transit';
    public const PARTIALLY_RECEIVED = 'partially_received';
    public const RECEIVED = 'received';
    public const CLOSED = 'closed';
    public const CANCELED = 'canceled';

    public const STATUSES = [self::REQUESTED, self::APPROVED, self::IN_TRANSIT, self::PARTIALLY_RECEIVED, self::RECEIVED, self::CLOSED, self::CANCELED];

    protected $fillable = [
        'reference', 'source_warehouse_id', 'destination_warehouse_id', 'status', 'notes',
        'requested_by', 'approved_by', 'dispatched_by', 'approved_at', 'dispatched_at', 'received_at', 'closed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime', 'dispatched_at' => 'datetime',
        'received_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function source()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destination()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(StockTransferReceipt::class);
    }
}
