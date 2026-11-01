<?php

namespace App\Modules\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;

/**
 * Cash on delivery: nothing to call. The payment is collected by the courier or at the counter
 * (PaymentService::recordCodCollection) and settled later (CodSettlementService).
 */
class CodGateway implements PaymentGateway
{
    public function code(): string
    {
        return Payment::PROVIDER_COD;
    }

    public function missingConfiguration(): array
    {
        return [];
    }

    public function initiate(Payment $payment, Order $order): PaymentInitiation
    {
        return new PaymentInitiation(null);
    }

    public function parseNotification(array $query, array $body, bool $isRedirect): GatewayNotification
    {
        return new GatewayNotification(false, GatewayNotification::FAILED, null, null, null, null, null);
    }

    public function fetchTransaction(string $transactionId): ?GatewayNotification
    {
        return null;
    }

    /** Cash refunds are paid out by a person and recorded by RefundService. */
    public function refund(Payment $payment, int $amountMinor): GatewayRefundResult
    {
        return new GatewayRefundResult(false, error: 'Cash refunds are paid out manually.');
    }
}
