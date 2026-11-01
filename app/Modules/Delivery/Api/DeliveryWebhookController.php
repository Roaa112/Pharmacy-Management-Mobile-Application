<?php

namespace App\Modules\Delivery\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryProvider;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Modules\Delivery\Providers\ProviderRegistry;
use App\Modules\Delivery\Services\OrderFulfilment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Status updates pushed by a delivery company.
 *
 * 401 for a bad signature (nothing stored). Otherwise 200 with what happened to each update:
 * a provider should not retry forever because one of its references is unknown to us, so
 * unknown shipments are logged and reported, not failed. A repeated event_id is a no-op.
 */
class DeliveryWebhookController extends Controller
{
    public function handle(Request $request, DeliveryProvider $provider, ProviderRegistry $registry, OrderFulfilment $fulfilment)
    {
        if (!$provider->is_active) {
            abort(404);
        }

        $adapter = $registry->for($provider);

        if (!$adapter->verifyWebhook($provider, $request)) {
            Log::warning('Delivery webhook rejected: bad signature', ['provider' => $provider->code, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $results = [];

        foreach ($adapter->parseWebhook($provider, $request) as $update) {
            $shipment = Shipment::where('delivery_provider_id', $provider->id)
                ->where(function ($q) use ($update) {
                    $q->when($update->externalReference, fn ($q) => $q->orWhere('external_reference', $update->externalReference))
                        ->when($update->trackingNumber, fn ($q) => $q->orWhere('tracking_number', $update->trackingNumber));
                })
                ->when(!$update->externalReference && !$update->trackingNumber, fn ($q) => $q->whereRaw('1 = 0'))
                ->first();

            if (!$shipment) {
                Log::warning('Delivery webhook for an unknown shipment', ['provider' => $provider->code, 'event_id' => $update->eventId]);
                $results[] = ['event_id' => $update->eventId, 'result' => 'unknown_shipment'];
                continue;
            }

            try {
                $status = $fulfilment->mapStatus($provider, $update->rawStatus);
                [$event, $new] = $fulfilment->record(
                    $shipment, $status, $update->rawStatus, ShipmentEvent::SOURCE_WEBHOOK, null,
                    $update->occurredAt, $update->payload, "{$provider->code}:{$update->eventId}", $update->note,
                );

                $results[] = ['event_id' => $update->eventId, 'result' => !$new ? 'duplicate' : ($status ? 'applied' : 'unmapped_status')];
            } catch (Throwable $e) {
                // Recorded nowhere: let the provider retry this one.
                report($e);
                $results[] = ['event_id' => $update->eventId, 'result' => 'error'];
            }
        }

        $failed = collect($results)->contains('result', 'error');

        return response()->json(['results' => $results], $failed ? 500 : 200);
    }
}
