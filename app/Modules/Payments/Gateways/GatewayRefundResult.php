<?php

namespace App\Modules\Payments\Gateways;

final class GatewayRefundResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly bool $pending = false,
        public readonly ?string $providerRefundId = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {
    }
}
