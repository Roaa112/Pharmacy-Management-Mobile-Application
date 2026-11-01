<?php

namespace App\Modules\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;

/**
 * A payment provider. The Order module never talks to a provider directly: PaymentService picks
 * the gateway by payments.provider. Adding a provider = one class implementing this interface,
 * registered in GatewayRegistry.
 */
interface PaymentGateway
{
    public function code(): string;

    /** Configuration keys that are missing (empty array = ready). Never returns secret values. */
    public function missingConfiguration(): array;

    /** Create the provider-side payment and return where the customer pays. */
    public function initiate(Payment $payment, Order $order): PaymentInitiation;

    /**
     * Parse and authenticate a provider notification (webhook body or customer redirect).
     * Must not throw for a forged request: return a notification with signatureValid = false.
     */
    public function parseNotification(array $query, array $body, bool $isRedirect): GatewayNotification;

    /** Server-side verification: the provider's own view of a transaction, or null if unavailable. */
    public function fetchTransaction(string $transactionId): ?GatewayNotification;

    public function refund(Payment $payment, int $amountMinor): GatewayRefundResult;
}
