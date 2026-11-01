<?php

namespace App\Modules\Payments\Gateways;

final class PaymentInitiation
{
    public function __construct(
        public readonly ?string $checkoutUrl,
        public readonly ?string $providerOrderId = null,
        /** Non-secret provider data worth keeping (intention id, payment key expiry...). */
        public readonly array $metadata = [],
    ) {
    }
}
