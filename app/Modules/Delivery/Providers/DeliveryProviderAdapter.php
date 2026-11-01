<?php

namespace App\Modules\Delivery\Providers;

use App\Models\DeliveryProvider;
use App\Models\Shipment;
use Illuminate\Http\Request;

/**
 * What the application needs from a delivery company. We never manage their drivers or
 * vehicles: we hand a shipment over and receive status updates.
 */
interface DeliveryProviderAdapter
{
    /** Hand the shipment over. Return the provider's reference/tracking data to store, or []. */
    public function createShipment(DeliveryProvider $provider, Shipment $shipment): array;

    /**
     * Withdraw a shipment from the provider (order canceled). true = done or nothing to withdraw;
     * false = the provider refused or could not be reached (the shipment is flagged for a person).
     */
    public function cancelShipment(DeliveryProvider $provider, Shipment $shipment): bool;

    /** Ask the provider for the shipment's current status; null when the provider only pushes updates. */
    public function fetchStatus(DeliveryProvider $provider, Shipment $shipment): ?WebhookUpdate;

    /** Whether the webhook request really comes from this provider. */
    public function verifyWebhook(DeliveryProvider $provider, Request $request): bool;

    /**
     * Status updates contained in a webhook request.
     *
     * @return list<WebhookUpdate>
     */
    public function parseWebhook(DeliveryProvider $provider, Request $request): array;
}
