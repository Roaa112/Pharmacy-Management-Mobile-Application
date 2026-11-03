<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a contact conversation: a pharmacy reply (admin_id) or a customer follow-up
 * (from_customer). A pharmacy reply records how it reached the customer (DeliverContactReply).
 */
class ContactMessageReply extends Model
{
    /** A reply still "sending" after this long was lost (the process died) and may be resent. */
    public const STUCK_AFTER_MINUTES = 5;

    protected $fillable = ['contact_message_id', 'admin_id', 'from_customer', 'body', 'sent_at', 'push_devices', 'delivery_error'];

    protected $casts = ['from_customer' => 'boolean', 'sent_at' => 'datetime'];

    public function contactMessage(): BelongsTo
    {
        return $this->belongsTo(ContactMessage::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function canResend(): bool
    {
        return !$this->from_customer && ($this->delivery_error !== null
            || ($this->sent_at === null && $this->created_at->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES))));
    }
}
