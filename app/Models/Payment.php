<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment attempt for an order. Status changes go through PaymentService only.
 * Provider credentials are never stored here; metadata holds non-secret provider data only.
 */
class Payment extends Model
{
    public const PROVIDER_COD = 'cod';
    public const PROVIDER_PAYMOB = 'paymob';
    public const PROVIDER_MANUAL_TRANSFER = 'manual_transfer';

    public const COLLECTION_PENDING = 'pending';
    public const COLLECTION_COLLECTED = 'collected';
    public const COLLECTION_PARTIAL = 'partially_collected';
    public const COLLECTION_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'payment_method_id', 'provider', 'internal_reference', 'provider_order_id', 'provider_transaction_id',
        'amount', 'currency', 'status', 'attempt', 'duplicate_of_payment_id', 'failure_reason', 'collection_status', 'collected_amount',
        'collected_via', 'collected_by', 'collected_at', 'cod_settlement_id', 'settled_at', 'refunded_amount',
        'verified_by', 'verified_at', 'receipt_path', 'paid_at', 'failed_at', 'expires_at', 'refunded_at', 'metadata',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'metadata' => 'array',
        'collected_at' => 'datetime',
        'settled_at' => 'datetime',
        'verified_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'expires_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function isCod(): bool
    {
        return $this->provider === self::PROVIDER_COD;
    }

    public function isOnline(): bool
    {
        return $this->provider === self::PROVIDER_PAYMOB;
    }

    public function amountMinor(): int
    {
        return Money::toMinor($this->amount);
    }

    /** Money received and not refunded. */
    public function refundableMinor(): int
    {
        $received = $this->isCod() ? Money::toMinor($this->collected_amount) : ($this->status->isSettledPayment() ? $this->amountMinor() : 0);

        return max(0, $received - Money::toMinor($this->refunded_amount));
    }

    /** Captured although the order was already paid by another attempt (refunded, never applied). */
    public function isDuplicateCapture(): bool
    {
        return $this->duplicate_of_payment_id !== null;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function history()
    {
        return $this->hasMany(PaymentStatusHistory::class)->orderBy('id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function settlement()
    {
        return $this->belongsTo(CodSettlement::class, 'cod_settlement_id');
    }

    public function collector()
    {
        return $this->belongsTo(Admin::class, 'collected_by');
    }

    public function verifier()
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }
}
