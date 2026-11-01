<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What happened to a shipment, from any source. Rows are never changed or deleted; status is
 * null when the provider sent a status nobody has mapped yet (shown to admins, order untouched).
 */
class ShipmentEvent extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_POLL = 'poll';
    public const SOURCE_SYSTEM = 'system';

    protected $fillable = [
        'shipment_id', 'status', 'raw_status', 'source', 'admin_id', 'occurred_at', 'payload',
        'idempotency_key', 'applied', 'note',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
        'applied' => 'boolean',
    ];

    protected static function booted()
    {
        static::updating(function (self $event) {
            // The only change allowed: marking the event as applied right after it is recorded.
            if (array_keys($event->getDirty()) !== ['applied']) {
                throw new LogicException('Shipment events cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Shipment events cannot be deleted.'));
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
