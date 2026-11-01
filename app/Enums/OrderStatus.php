<?php

namespace App\Enums;

/**
 * Order lifecycle. The transition graph is protected code: stock, payments, points and refunds
 * depend on it. Labels are display text (lang/{ar,en}/orders.php).
 *
 * pending -> confirmed -> picking -> packed -> on_delivery -> completed; canceled from any
 * non-final state. Warehouse steps may be skipped. Payment, shipment and refund have their own
 * statuses (PaymentStatus, ShipmentStatus, RefundStatus).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Picking = 'picking';
    case Packed = 'packed';
    case OnDelivery = 'on_delivery';
    case Completed = 'completed';
    case Canceled = 'canceled';

    /** Forward order of the fulfilment flow; steps may be skipped. */
    public const FLOW = ['pending', 'confirmed', 'picking', 'packed', 'on_delivery', 'completed'];

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->isFinal() || $next === $this) {
            return false;
        }

        if ($next === self::Canceled) {
            return true;
        }

        return array_search($next->value, self::FLOW, true) > array_search($this->value, self::FLOW, true);
    }

    /** The units have left the warehouse (reservations fulfilled). */
    public function isDispatched(): bool
    {
        return $this === self::OnDelivery || $this === self::Completed;
    }

    /**
     * The status customer apps receive in `status`. Apps released before the warehouse steps
     * know five statuses; picking and packed are "confirmed, being prepared" for them. The exact
     * step is sent separately as `fulfillment_stage`.
     */
    public function customerStatus(): self
    {
        return match ($this) {
            self::Picking, self::Packed => self::Confirmed,
            default => $this,
        };
    }

    /** What the customer app has always received (Arabic, kept exactly for compatibility). */
    public function legacyMeaning(): string
    {
        return __('orders.status_meaning.'.$this->customerStatus()->value, [], 'ar');
    }

    public function label(?string $locale = null): string
    {
        return __('orders.status.'.$this->value, [], $locale);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
