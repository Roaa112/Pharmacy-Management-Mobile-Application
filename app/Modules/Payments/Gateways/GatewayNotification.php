<?php

namespace App\Modules\Payments\Gateways;

/** A provider's statement about one transaction, after signature checking. */
final class GatewayNotification
{
    public const SUCCESS = 'success';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const REFUND = 'refund';
    public const VOID = 'void';

    public function __construct(
        public readonly bool $signatureValid,
        public readonly string $outcome,
        public readonly ?string $transactionId,
        public readonly ?string $providerOrderId,
        public readonly ?string $merchantReference,
        public readonly ?int $amountProviderUnits,
        public readonly ?string $currency,
        public readonly ?string $parentTransactionId = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
        // Total refunded on this transaction so far, as the provider reports it (refund reconciliation).
        public readonly ?int $refundedAmountProviderUnits = null,
    ) {
    }

    /**
     * Stable key of this exact statement: a retried notification produces the same key. The
     * customer's browser redirect uses its own channel, so it can never make the server-to-server
     * webhook look like a duplicate (applying the same verified outcome twice changes nothing).
     */
    public function idempotencyKey(string $provider, ?string $channel = null): string
    {
        return implode(':', array_filter([$provider, $channel, 'txn', $this->transactionId ?? 'none', $this->outcome], fn ($part) => $part !== null));
    }
}
