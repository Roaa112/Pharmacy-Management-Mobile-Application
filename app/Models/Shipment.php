<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'delivery_provider_id', 'delivery_zone_id', 'delivery_rate_id', 'status',
        'tracking_number', 'external_reference', 'tracking_url', 'fee_charged', 'provider_cost',
        'cod_amount', 'cod_collected_at', 'cod_settled_at', 'recipient_name', 'recipient_phone',
        'address_snapshot', 'assigned_at', 'picked_up_at', 'delivered_at', 'failed_at',
        'failure_reason', 'attempts', 'needs_attention', 'meta', 'estimated_delivery_at',
    ];

    protected $casts = [
        'status' => ShipmentStatus::class,
        'address_snapshot' => 'array',
        'meta' => 'array',
        'needs_attention' => 'boolean',
        'cod_collected_at' => 'datetime',
        'cod_settled_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'estimated_delivery_at' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function provider()
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function zone()
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** Shape added to the customer order endpoints (additive). */
    public function toCustomerArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'provider' => $this->provider?->name,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'delivered_at' => $this->delivered_at?->toDateTimeString(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toDateString(),
            'events' => $this->events->whereNotNull('status')->map(fn (ShipmentEvent $event) => [
                'status' => $event->status,
                'status_label' => ShipmentStatus::from($event->status)->label(),
                'occurred_at' => $event->occurred_at->toDateTimeString(),
            ])->values(),
        ];
    }
}
