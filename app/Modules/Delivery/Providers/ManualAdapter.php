<?php

namespace App\Modules\Delivery\Providers;

use App\Models\DeliveryProvider;
use App\Models\Shipment;
use Illuminate\Http\Request;

/**
 * Our own team, or a company we deal with by phone: nothing is sent anywhere and admins update
 * the shipment from the dashboard. Webhooks are not accepted.
 */
class ManualAdapter implements DeliveryProviderAdapter
{
    public function createShipment(DeliveryProvider $provider, Shipment $shipment): array
    {
        return [];
    }

    public function cancelShipment(DeliveryProvider $provider, Shipment $shipment): bool
    {
        return true; // nothing was sent to any system
    }

    public function fetchStatus(DeliveryProvider $provider, Shipment $shipment): ?WebhookUpdate
    {
        return null;
    }

    public function verifyWebhook(DeliveryProvider $provider, Request $request): bool
    {
        return false;
    }

    public function parseWebhook(DeliveryProvider $provider, Request $request): array
    {
        return [];
    }
}
