<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money given back to a customer. Requested by an accepted return or a canceled paid order;
 * processed by RefundService (through the gateway for online payments, or recorded as paid out
 * in cash / manually with a reference). Only succeeded refunds count against the payment.
 */
class Refund extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';
    /** Sent to the provider without a usable answer: it may or may not have been paid out. Reconciled, never retried blindly. */
    public const UNKNOWN = 'unknown';

    /** Counted against what is left to refund on the payment. */
    public const OPEN = [self::PENDING, self::PROCESSING, self::UNKNOWN];
    /** Possibly already paid out by the provider. */
    public const IN_FLIGHT = [self::PROCESSING, self::UNKNOWN];

    public const METHOD_GATEWAY = 'gateway';
    public const METHOD_CASH = 'cash';
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'reference', 'order_id', 'payment_id', 'return_request_id', 'amount', 'currency', 'method', 'status',
        'provider_refund_id', 'external_reference', 'reason', 'failure_reason', 'requested_by', 'processed_by',
        'processed_at', 'metadata',
    ];

    protected $casts = ['metadata' => 'array', 'processed_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function requester()
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    public function processor()
    {
        return $this->belongsTo(Admin::class, 'processed_by');
    }
}
