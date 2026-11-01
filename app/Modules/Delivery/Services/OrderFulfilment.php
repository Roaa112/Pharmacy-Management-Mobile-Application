<?php

namespace App\Modules\Delivery\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\ProviderStatusMap;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Modules\Delivery\Providers\ProviderRegistry;
use App\Modules\Inventory\Services\InventoryService;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Modules\Payments\Services\CodSettlementService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Support\Money;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single path from "something happened to a delivery" to the order. Dashboard actions and
 * provider webhooks both record a ShipmentEvent here; applying it updates the shipment and, via
 * OrderWorkflow, the order. Events are idempotent (unique key), so a provider resending the same
 * update, or two requests racing, change nothing the second time.
 */
class OrderFulfilment
{
    public function __construct(
        private OrderWorkflow $workflow,
        private ProviderRegistry $providers,
        private InventoryService $inventory,
    ) {
    }

    /** Called inside checkout's transaction for an order delivered to an address. */
    public function createShipment(Order $order, Address $address, DeliveryQuote $quote): Shipment
    {
        $provider = $quote->rate?->provider ?? DeliveryProvider::default();

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'delivery_provider_id' => $provider?->id,
            'delivery_zone_id' => $quote->zone?->id,
            'delivery_rate_id' => $quote->rate?->id,
            'status' => ShipmentStatus::Pending->value,
            'fee_charged' => Money::fromMinor($quote->feeMinor),
            'cod_amount' => $order->payment_method === 'cash' ? $order->total_price : 0,
            'recipient_name' => $order->user?->name,
            'recipient_phone' => $order->user?->phone,
            'address_snapshot' => $address->snapshot(),
            'estimated_delivery_at' => $quote->rate?->eta_max_days !== null ? now()->addDays($quote->rate->eta_max_days)->toDateString() : null,
        ]);

        $this->record($shipment, ShipmentStatus::Pending, null, ShipmentEvent::SOURCE_SYSTEM, null, now(), [], "shipment-created:{$shipment->id}");

        return $shipment;
    }

    /** Hand a shipment to a provider (dashboard). Calls the provider's adapter, then records "assigned". */
    public function assign(Shipment $shipment, DeliveryProvider $provider, ?string $trackingNumber, ?string $externalReference, ?int $adminId): Shipment
    {
        $shipment->update(['delivery_provider_id' => $provider->id]);

        $data = $this->providers->for($provider)->createShipment($provider, $shipment->fresh());
        $tracking = $data['tracking_number'] ?? $trackingNumber;

        $shipment->update([
            'tracking_number' => $tracking,
            'external_reference' => $data['external_reference'] ?? $externalReference,
            'tracking_url' => $data['tracking_url'] ?? $provider->trackingUrlFor($tracking),
        ]);

        $this->record($shipment, ShipmentStatus::Assigned, null, ShipmentEvent::SOURCE_ADMIN, $adminId, now(), [], 'admin:'.uniqid('', true));

        return $shipment->fresh();
    }

    /**
     * Ask the provider for the current status (for providers with a status API) and record it
     * through the same event path as webhooks. Returns whether a new event was recorded.
     */
    public function poll(Shipment $shipment): bool
    {
        if (!$shipment->provider) {
            return false;
        }

        $update = $this->providers->for($shipment->provider)->fetchStatus($shipment->provider, $shipment);
        if (!$update) {
            return false;
        }

        [, $new] = $this->record($shipment, $this->mapStatus($shipment->provider, $update->rawStatus), $update->rawStatus, ShipmentEvent::SOURCE_POLL,
            null, $update->occurredAt, $update->payload, "{$shipment->provider->code}:{$update->eventId}", $update->note);

        return $new;
    }

    /** Translate a provider's own status. null = nobody has mapped it yet. */
    public function mapStatus(?DeliveryProvider $provider, string $rawStatus): ?ShipmentStatus
    {
        $raw = trim($rawStatus);

        if ($provider) {
            $mapped = ProviderStatusMap::where('delivery_provider_id', $provider->id)
                ->whereRaw('lower(external_status) = ?', [mb_strtolower($raw)])
                ->value('internal_status');

            if ($mapped) {
                return ShipmentStatus::tryFrom($mapped);
            }
        }

        // A provider may report our own vocabulary directly.
        return ShipmentStatus::tryFrom(mb_strtolower($raw));
    }

    /**
     * Record an event and apply it. Returns the event and whether it was new.
     *
     * @return array{0: ShipmentEvent, 1: bool}
     */
    public function record(
        Shipment $shipment,
        ?ShipmentStatus $status,
        ?string $rawStatus,
        string $source,
        ?int $adminId,
        Carbon $occurredAt,
        array $payload,
        string $idempotencyKey,
        ?string $note = null,
    ): array {
        try {
            return DB::transaction(function () use ($shipment, $status, $rawStatus, $source, $adminId, $occurredAt, $payload, $idempotencyKey, $note) {
                if ($existing = ShipmentEvent::where('idempotency_key', $idempotencyKey)->first()) {
                    return [$existing, false];
                }

                $event = ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'status' => $status?->value,
                    'raw_status' => $rawStatus,
                    'source' => $source,
                    'admin_id' => $adminId,
                    'occurred_at' => $occurredAt,
                    'payload' => $payload ?: null,
                    'idempotency_key' => $idempotencyKey,
                    'note' => $note,
                ]);

                $this->apply($event);

                return [$event->fresh(), true];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request recorded the same event at the same moment.
            return [ShipmentEvent::where('idempotency_key', $idempotencyKey)->firstOrFail(), false];
        }
    }

    private function apply(ShipmentEvent $event): void
    {
        $shipment = Shipment::lockForUpdate()->findOrFail($event->shipment_id);

        // Unmapped provider status: keep it, flag it, touch nothing else.
        if ($event->status === null) {
            $shipment->update(['needs_attention' => true]);

            return;
        }

        $status = ShipmentStatus::from($event->status);

        // A delivered, returned or canceled shipment does not move again.
        if ($shipment->status->isFinal() && $shipment->status !== $status) {
            return;
        }

        $changes = ['status' => $status->value, 'needs_attention' => $status->needsAttention()];
        $at = $event->occurred_at;

        match ($status) {
            ShipmentStatus::Assigned => $changes['assigned_at'] = $shipment->assigned_at ?? $at,
            ShipmentStatus::PickedUp => $changes['picked_up_at'] = $shipment->picked_up_at ?? $at,
            ShipmentStatus::Delivered => $changes += [
                'delivered_at' => $at,
                'cod_collected_at' => Money::toMinor($shipment->cod_amount) > 0 ? ($shipment->cod_collected_at ?? $at) : null,
            ],
            ShipmentStatus::Failed => $changes += [
                'failed_at' => $at,
                'attempts' => $shipment->attempts + 1,
                'failure_reason' => $event->note ?? $shipment->failure_reason,
            ],
            default => null,
        };

        $shipment->update($changes);
        $event->update(['applied' => true]);

        $this->applyToPayment($shipment, $status, $event);

        // Goods that come back undelivered return to quarantine; a person inspects them.
        if ($status === ShipmentStatus::Returned && $shipment->order) {
            foreach ($shipment->order->items as $item) {
                $this->inventory->returnUndelivered($item, ['admin_id' => $event->admin_id, 'reason' => "Shipment #{$shipment->id} returned"]);
            }
        }

        $this->moveOrder($shipment, $status, $event);
    }

    /** Delivered = the courier collected the cash still due; failed = collection failed (still due). */
    private function applyToPayment(Shipment $shipment, ShipmentStatus $status, ShipmentEvent $event): void
    {
        $payment = Payment::where('order_id', $shipment->order_id)->latest('id')->first();

        if (!$payment?->isCod()) {
            return;
        }

        $actor = $event->source === ShipmentEvent::SOURCE_ADMIN ? PaymentService::ACTOR_ADMIN : PaymentService::ACTOR_PROVIDER;
        $payments = app(PaymentService::class);

        if ($status === ShipmentStatus::Delivered) {
            $dueMinor = $payment->amountMinor() - Money::toMinor($payment->collected_amount);
            if ($dueMinor > 0 && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::PartiallyPaid], true)) {
                $payments->recordCodCollection($payment, $dueMinor, 'courier', $actor, $event->admin_id, "Shipment #{$shipment->id} delivered");
            }
        } elseif ($status === ShipmentStatus::Failed) {
            $payments->recordCodCollectionFailure($payment, $event->note ?? 'Delivery failed', $actor, $event->admin_id);
        }
    }

    private function moveOrder(Shipment $shipment, ShipmentStatus $status, ShipmentEvent $event): void
    {
        $target = $status->orderStatus();
        $order = $shipment->order;

        if (!$target || !$order || !$order->canTransitionTo($target->value)) {
            return;
        }

        // Delivered skips straight to completed even if nobody marked "on delivery".
        $actor = $event->source === ShipmentEvent::SOURCE_ADMIN ? OrderWorkflow::ACTOR_ADMIN : OrderWorkflow::ACTOR_PROVIDER;

        try {
            $this->workflow->transition($order, $target, $actor, $event->admin_id, "Shipment #{$shipment->id}: {$status->value}");
        } catch (DomainException) {
            // The order moved on in the meantime; the shipment event stays recorded.
        }
    }

    /**
     * Settle the cash of one shipment (the full collected amount was handed over). Several
     * shipments at once, or a different received amount, go through CodSettlementService.
     */
    public function settleCod(Shipment $shipment, ?int $adminId = null): Shipment
    {
        if (!$shipment->cod_collected_at) {
            throw new DomainException('This shipment has no collected cash to settle.');
        }

        if ($shipment->cod_settled_at) {
            return $shipment;
        }

        $payment = Payment::where('order_id', $shipment->order_id)->where('provider', Payment::PROVIDER_COD)->whereNull('settled_at')
            ->where('collected_amount', '>', 0)->latest('id')->first();

        if ($payment) {
            app(CodSettlementService::class)->settle([$payment->id], Money::toMinor($payment->collected_amount), $shipment->delivery_provider_id, null, "Shipment #{$shipment->id}", $adminId);
        } else {
            $shipment->update(['cod_settled_at' => now()]);
        }

        return $shipment->fresh();
    }

    /** The dashboard's manual status change on an order without going through a shipment. */
    public function orderStatusFromDashboard(Order $order, OrderStatus $to, ?int $adminId): Order
    {
        return $this->workflow->transition($order, $to, OrderWorkflow::ACTOR_ADMIN, $adminId);
    }
}
