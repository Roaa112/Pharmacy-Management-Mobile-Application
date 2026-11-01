<?php

namespace App\Modules\Payments\Gateways;

use DomainException;

/** Gateways by provider code. Legacy manual transfers have none: they were verified by hand. */
class GatewayRegistry
{
    private const GATEWAYS = [
        'cod' => CodGateway::class,
        'paymob' => PaymobGateway::class,
    ];

    public function for(string $provider): PaymentGateway
    {
        $class = self::GATEWAYS[$provider] ?? throw new DomainException("No payment gateway for provider {$provider}.");

        return app($class);
    }

    public function has(string $provider): bool
    {
        return isset(self::GATEWAYS[$provider]);
    }

    /** A provider can take payments: it exists and has its configuration. */
    public function isReady(string $provider): bool
    {
        return $this->has($provider) && $this->for($provider)->missingConfiguration() === [];
    }
}
