<?php

namespace App\Modules\Delivery\Providers;

use App\Models\DeliveryProvider;
use InvalidArgumentException;

/** Adapter keys an admin can pick for a provider. A new integration is one class plus one line here. */
class ProviderRegistry
{
    public const ADAPTERS = [
        'manual' => ManualAdapter::class,
        'signed_webhook' => SignedWebhookAdapter::class,
    ];

    public function for(DeliveryProvider $provider): DeliveryProviderAdapter
    {
        $class = self::ADAPTERS[$provider->adapter] ?? null;

        if (!$class) {
            throw new InvalidArgumentException("Unknown delivery adapter [{$provider->adapter}].");
        }

        return app($class);
    }
}
