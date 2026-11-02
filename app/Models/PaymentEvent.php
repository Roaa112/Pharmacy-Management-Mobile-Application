<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every provider callback and internal payment event, stored before it is acted on. The
 * idempotency key is unique: a provider retrying the same notification is recorded once.
 *
 * Lifecycle of a provider event: processing (claimed by one worker) -> processed | rejected |
 * ignored, or failed (our own error). "received" is the state of rows stored before claims
 * existed. received, failed and stale processing events are retryable: a provider retry or
 * payments:recover-events claims them again (claim() is one atomic UPDATE, so only one worker
 * wins). An event left "processing" by a killed process becomes stale after STALE_AFTER_MINUTES.
 */
class PaymentEvent extends Model
{
    public const RESULT_RECEIVED = 'received';
    public const RESULT_PROCESSING = 'processing';
    public const RESULT_PROCESSED = 'processed';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_REJECTED = 'rejected';
    public const RESULT_FAILED = 'failed';
    public const RESULT_IGNORED = 'ignored';

    /** Longer than any request can run (PHP time limit + provider HTTP timeout). */
    public const STALE_AFTER_MINUTES = 5;

    /** Automatic retries stop here; the event is reported for a person to look at. */
    public const MAX_ATTEMPTS = 10;

    protected $fillable = [
        'payment_id', 'provider', 'event_type', 'provider_event_id', 'idempotency_key', 'signature_valid',
        'result', 'attempts', 'processing_started_at', 'error', 'payload', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime',
        'processing_started_at' => 'datetime', 'attempts' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Take this event for processing. True for exactly one caller; false when it is already
     * done, or being processed by another worker that has not gone stale.
     */
    public function claim(): bool
    {
        $claimed = static::whereKey($this->id)
            ->where(fn (Builder $q) => static::constrainRetryable($q))
            ->update([
                'result' => self::RESULT_PROCESSING,
                'processing_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    /** Provider statements (not internal records) that still need applying. */
    public function scopeRetryable(Builder $query): Builder
    {
        return $query->where('signature_valid', true)
            ->where(fn (Builder $q) => $q->where('event_type', 'like', 'webhook.%')->orWhere('event_type', 'like', 'redirect.%')->orWhere('event_type', 'like', 'inquiry.%'))
            ->where(fn (Builder $q) => static::constrainRetryable($q));
    }

    public function isInProgress(): bool
    {
        return $this->result === self::RESULT_PROCESSING
            && $this->processing_started_at
            && $this->processing_started_at->gte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private static function constrainRetryable(Builder $query): Builder
    {
        return $query->whereIn('result', [self::RESULT_RECEIVED, self::RESULT_FAILED])
            ->orWhere(fn (Builder $q) => $q->where('result', self::RESULT_PROCESSING)
                ->where(fn (Builder $q) => $q->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))));
    }
}
