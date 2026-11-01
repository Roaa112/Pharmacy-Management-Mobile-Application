<?php

namespace App\Modules\Delivery\Providers;

use App\Models\DeliveryProvider;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A generic contract any delivery company can implement without custom code on our side:
 *
 *   POST /api/webhooks/delivery/{provider code}
 *   X-Signature: hex(HMAC-SHA256(raw body, webhook secret))
 *   {"event_id": "...", "reference": "<our or their reference>", "tracking_number": "...",
 *    "status": "<their status>", "occurred_at": "ISO-8601", "note": "..."}
 *
 * A body may also be {"events": [ ...the same objects... ]}. Their statuses are translated with
 * the provider's status map. Shipments are handed over manually (tracking number entered by an
 * admin); a company with an ordering API gets its own adapter implementing createShipment().
 */
class SignedWebhookAdapter implements DeliveryProviderAdapter
{
    public function createShipment(DeliveryProvider $provider, Shipment $shipment): array
    {
        return [];
    }

    public function cancelShipment(DeliveryProvider $provider, Shipment $shipment): bool
    {
        // The generic contract has no API to call: the company is told by the admin, and its
        // own "canceled" status arrives by webhook.
        return true;
    }

    public function fetchStatus(DeliveryProvider $provider, Shipment $shipment): ?WebhookUpdate
    {
        return null;
    }

    public function verifyWebhook(DeliveryProvider $provider, Request $request): bool
    {
        $secret = $provider->webhook_secret;
        $signature = (string) $request->header('X-Signature');

        if (!$secret || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature));
    }

    public function parseWebhook(DeliveryProvider $provider, Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return [];
        }

        $events = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];
        $updates = [];

        foreach ($events as $event) {
            if (!is_array($event) || !is_string($event['status'] ?? null) || !is_scalar($event['event_id'] ?? null)) {
                continue;
            }

            try {
                $occurredAt = isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : now();
            } catch (\Throwable) {
                $occurredAt = now();
            }

            $updates[] = new WebhookUpdate(
                externalReference: isset($event['reference']) ? (string) $event['reference'] : null,
                trackingNumber: isset($event['tracking_number']) ? (string) $event['tracking_number'] : null,
                rawStatus: mb_substr($event['status'], 0, 100),
                eventId: (string) $event['event_id'],
                occurredAt: $occurredAt,
                payload: $event,
                note: isset($event['note']) ? mb_substr((string) $event['note'], 0, 255) : null,
            );
        }

        return $updates;
    }
}
