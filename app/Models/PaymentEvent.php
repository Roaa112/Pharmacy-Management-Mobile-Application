<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every provider callback and internal payment event, stored before it is acted on. The
 * idempotency key is unique: a provider retrying the same notification is recorded once.
 */
class PaymentEvent extends Model
{
    public const RESULT_RECEIVED = 'received';
    public const RESULT_PROCESSED = 'processed';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_REJECTED = 'rejected';
    public const RESULT_FAILED = 'failed';
    public const RESULT_IGNORED = 'ignored';

    protected $fillable = [
        'payment_id', 'provider', 'event_type', 'provider_event_id', 'idempotency_key', 'signature_valid',
        'result', 'error', 'payload', 'processed_at',
    ];

    protected $casts = ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
