<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One reminder for one event occurrence. The unique key (type, reference, occurrence) is what
 * makes a reminder impossible to queue twice, even when two schedulers run at once; claim()
 * makes it impossible for two queue workers to send it twice.
 *
 * sending (queued) -> delivering (claimed by one worker) -> sent | skipped | failed
 */
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DELIVERING = 'delivering';

    /** A claim older than this belongs to a worker that died: the reminder may be claimed again. */
    public const CLAIM_TIMEOUT_MINUTES = 10;
    public const MAX_ATTEMPTS = 3;

    protected $fillable = ['user_id', 'type', 'reference_type', 'reference_id', 'occurrence', 'status', 'devices', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime', 'claimed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Take this reminder for sending, atomically: one conditional UPDATE, so of any number of
     * workers running the same job at once exactly one gets true. A reminder already sent,
     * skipped or failed, or claimed less than CLAIM_TIMEOUT_MINUTES ago, is not taken.
     */
    public static function claim(int $id): bool
    {
        return static::whereKey($id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->where('status', self::STATUS_SENDING)
                ->orWhere(fn ($q) => $q->where('status', self::STATUS_DELIVERING)->where('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))))
            ->update(['status' => self::STATUS_DELIVERING, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
    }
}
