<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Points exchanged for something: a reward (source "reward") or a discount on an order
 * (source "checkout"). Its points left the customer's lots through ledger_entry_id; a
 * cancellation or expiry gives them back through a "cancelled" ledger entry.
 *
 * Statuses: pending → approved → fulfilled; pending/approved → cancelled (by staff) or expired
 * (not collected in time). Checkout redemptions are fulfilled at once and are undone by the
 * order's cancellation.
 */
class LoyaltyRedemption extends Model
{
    public const SOURCE_REWARD = 'reward';
    public const SOURCE_CHECKOUT = 'checkout';

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const STATUSES = [self::PENDING, self::APPROVED, self::FULFILLED, self::CANCELLED, self::EXPIRED];
    public const OPEN = [self::PENDING, self::APPROVED];

    protected $fillable = [
        'number', 'user_id', 'reward_id', 'order_id', 'source', 'quantity', 'points', 'value', 'status', 'reward_snapshot',
        'ledger_entry_id', 'created_by', 'expires_at', 'idempotency_key', 'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'points' => 'integer',
        'reward_snapshot' => 'array',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id')->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LoyaltyLedgerEntry::class, 'ledger_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'cancelled_by');
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'fulfilled_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function title(): string
    {
        return $this->source === self::SOURCE_CHECKOUT
            ? 'Points paid on order #'.$this->order_id
            : ($this->reward_snapshot['title'] ?? $this->reward?->title ?? 'Reward');
    }
}
