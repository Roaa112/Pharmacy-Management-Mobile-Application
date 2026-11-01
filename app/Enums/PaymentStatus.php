<?php

namespace App\Enums;

/**
 * Status of a payment (payments.status) and, projected by PaymentService, of the order
 * (orders.payment_status). "unpaid" is the order-level name older apps know for a payment
 * not received yet (cash on delivery before collection, or an order without a payment row).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /** The attempt is over (only a verified late provider success can still turn it paid). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Canceled, self::Expired, self::Refunded], true);
    }

    /** Money was received (possibly partly refunded since). */
    public function isSettledPayment(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    /**
     * Allowed payment transitions. A paid payment can only move to (partially) refunded; a
     * failed/expired/canceled one never becomes paid again (a retry is a new payment row).
     */
    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return false;
        }

        return match ($this) {
            self::Unpaid, self::Pending => in_array($next, [self::AwaitingVerification, self::Paid, self::PartiallyPaid, self::Failed, self::Canceled, self::Expired], true),
            self::AwaitingVerification => in_array($next, [self::Paid, self::Pending, self::Failed, self::Canceled], true),
            self::PartiallyPaid => in_array($next, [self::Paid, self::Failed, self::Canceled, self::PartiallyRefunded, self::Refunded], true),
            self::Paid => in_array($next, [self::PartiallyRefunded, self::Refunded], true),
            self::PartiallyRefunded => $next === self::Refunded,
            // A verified provider success after the customer's session failed or expired: the money
            // was received and must be recorded (PaymentService refunds it if the order is gone).
            self::Failed, self::Expired, self::Canceled => $next === self::Paid,
            default => false,
        };
    }

    public function label(?string $locale = null): string
    {
        return __('payments.status.'.$this->value, [], $locale);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
