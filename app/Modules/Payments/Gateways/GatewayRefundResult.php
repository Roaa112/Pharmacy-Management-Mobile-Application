<?php

namespace App\Modules\Payments\Gateways;

/**
 * What the provider said about a refund request. uncertain = no usable answer (timeout, dropped
 * connection, provider error): the refund may or may not have been made, so it must be reconciled
 * with the provider, never retried blindly.
 */
final class GatewayRefundResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly bool $pending = false,
        public readonly ?string $providerRefundId = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
        public readonly bool $uncertain = false,
    ) {
    }
}
