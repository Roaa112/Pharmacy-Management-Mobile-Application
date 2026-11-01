<?php

namespace App\Enums;

/**
 * Our own view of a delivery, whoever performs it. Provider statuses are mapped onto these
 * (provider_status_maps); the order follows from them (OrderFulfilment).
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Canceled], true);
    }

    /** The order status this shipment status moves the order to, if any. */
    public function orderStatus(): ?OrderStatus
    {
        return match ($this) {
            self::PickedUp, self::InTransit => OrderStatus::OnDelivery,
            self::Delivered => OrderStatus::Completed,
            default => null,
        };
    }

    /** failed and returned need a person to decide; they never cancel the order by themselves. */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Returned;
    }

    public function label(?string $locale = null): string
    {
        return __('delivery.status.'.$this->value, [], $locale);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
