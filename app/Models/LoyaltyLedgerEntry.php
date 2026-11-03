<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One points movement (see App\Modules\Points\Services\LoyaltyLedger). Signed points: credits
 * (earned, bonus, adjusted +, cancelled = points given back) are positive, debits (redeemed,
 * expired, reversed, refunded, adjusted −) negative.
 *
 * Credit entries that bring new points are "lots": remaining_points says how many are still
 * usable, status where the lot is in its life (pending → active → depleted / expired, or void
 * when its order was canceled before the points became usable), expires_at when they lapse.
 * Debits record which lots they took from in loyalty_ledger_allocations.
 *
 * Nothing about what happened can change: an entry's points, type, customer, order and reference
 * are fixed. Only a lot's bookkeeping (remaining points, status, dates) moves, and only through
 * LoyaltyLedger. Entries are never deleted.
 */
class LoyaltyLedgerEntry extends Model
{
    public const EARNED = 'earned';
    public const BONUS = 'bonus';
    public const ADJUSTED = 'adjusted';
    public const REDEEMED = 'redeemed';
    public const EXPIRED = 'expired';
    public const REVERSED = 'reversed';   // the earn of a canceled order
    public const REFUNDED = 'refunded';   // points taken back by a refund (decision D2)
    public const CANCELLED = 'cancelled'; // points given back: a redemption or a checkout payment undone

    public const TYPES = [self::EARNED, self::BONUS, self::ADJUSTED, self::REDEEMED, self::EXPIRED, self::REVERSED, self::REFUNDED, self::CANCELLED];

    public const STATUS_POSTED = 'posted';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPLETED = 'depleted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_VOID = 'void';

    /** @deprecated old type names, kept for older callers */
    public const EARN = self::EARNED;
    public const REVERSE = self::REFUNDED;
    public const REDEEM = self::REDEEMED;
    public const EXPIRE = self::EXPIRED;

    /** Lot bookkeeping: the only columns that may change after an entry is written. */
    private const MUTABLE = ['remaining_points', 'status', 'earned_at', 'expires_at', 'redeemed_at', 'reversed_at', 'updated_at'];

    protected $table = 'loyalty_ledger';

    protected $fillable = [
        'user_id', 'order_id', 'type', 'points', 'reference_type', 'reference_id', 'admin_id', 'occurred_at',
        'remaining_points', 'status', 'description', 'earned_at', 'expires_at', 'metadata', 'idempotency_key',
    ];

    protected $casts = [
        'points' => 'integer',
        'remaining_points' => 'integer',
        'occurred_at' => 'datetime',
        'earned_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::updating(function (self $entry) {
            if (!$entry->isLot() || array_diff(array_keys($entry->getDirty()), self::MUTABLE)) {
                throw new LogicException('Loyalty ledger entries cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Loyalty ledger entries cannot be deleted.'));
    }

    public function isLot(): bool
    {
        return $this->getOriginal('remaining_points') !== null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The admin who made the movement (adjustments, redemptions on behalf, decisions). */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoyaltyAllocation::class, 'entry_id');
    }

    public function lotAllocations(): HasMany
    {
        return $this->hasMany(LoyaltyAllocation::class, 'lot_id');
    }

    public function scopeLots(Builder $query): Builder
    {
        return $query->whereNotNull('remaining_points');
    }

    /** Lots that can be spent right now (active, points left, not past their expiry). */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->lots()->where('status', self::STATUS_ACTIVE)->where('remaining_points', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpiredNow(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }
}
