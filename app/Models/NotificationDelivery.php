<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One reminder for one event occurrence. The unique key (type, reference, occurrence) is what
 * makes a reminder impossible to send twice, even when two schedulers run at once.
 */
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SENDING = 'sending';

    protected $fillable = ['user_id', 'type', 'reference_type', 'reference_id', 'occurrence', 'status', 'devices', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
