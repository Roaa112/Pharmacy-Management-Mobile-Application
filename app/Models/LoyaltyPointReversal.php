<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Points taken back for an accepted refund (decision D2). Rows are never updated or deleted.
 *
 * points_status_at_reversal "redeemed" means the customer had already spent these points:
 * they are owed back (shown to the admin), not silently ignored.
 */
class LoyaltyPointReversal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'user_id', 'return_request_id', 'admin_id', 'points',
        'refunded_total', 'reversed_total', 'points_status_at_reversal',
    ];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Loyalty point reversals cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Loyalty point reversals cannot be deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
