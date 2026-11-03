<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A conversation a customer started from the app. Replies (both sides) are ContactMessageReply. */
class ContactMessage extends Model
{
    public const STATUS_OPEN = 'open';       // waiting for the pharmacy
    public const STATUS_REPLIED = 'replied'; // the pharmacy answered last

    protected $fillable = [
        'name',
        'email',
        'message',
        'user_id',
        'status',
        'last_activity_at',
    ];

    protected $casts = ['last_activity_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(function (ContactMessage $message) {
            $message->status ??= self::STATUS_OPEN;
            $message->last_activity_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ContactMessageReply::class)->orderBy('id');
    }
}
