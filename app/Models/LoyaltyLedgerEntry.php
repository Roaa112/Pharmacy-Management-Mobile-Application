<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only record of every points movement (earn / reverse / redeem / expire). Balances are
 * computed by App\Services\LoyaltyService; the ledger is the audit trail and the base for a
 * future balance-based programme.
 */
class LoyaltyLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const EARN = 'earn';
    public const REVERSE = 'reverse';
    public const REDEEM = 'redeem';
    public const EXPIRE = 'expire';

    protected $table = 'loyalty_ledger';

    protected $fillable = ['user_id', 'order_id', 'type', 'points', 'reference_type', 'reference_id', 'admin_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Loyalty ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Loyalty ledger entries cannot be deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
