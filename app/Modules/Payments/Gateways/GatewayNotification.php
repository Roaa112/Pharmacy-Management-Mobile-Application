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
    ) {
    }

    /** Stable key of this exact statement: a retried notification produces the same key. */
    public function idempotencyKey(string $provider): string
    {
        return implode(':', [$provider, 'txn', $this->transactionId ?? 'none', $this->outcome]);
    }
}
