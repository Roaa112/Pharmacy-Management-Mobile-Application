<?php

namespace App\Modules\Orders\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Payment;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Promotions\Services\PromotionLifecycle;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes an order's status. Dashboard actions, customer cancellation and
 * shipment events (OrderFulfilment) all come through here, so the side effects of a transition
 * (stock back on cancel, timestamps, payment status, history) happen exactly once.
 *
 * Lock order (every path that changes an order follows it): order -> product sizes -> stock rows
 * -> shipments -> payments -> refunds. Holding the order row first serializes all work on one order.
 */
class OrderWorkflow
{
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_PROVIDER = 'provider';
    public const ACTOR_SYSTEM = 'system';

    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * @throws DomainException when the transition is not allowed (nothing is changed)
     */
    public function transition(Order $order, OrderStatus $to, string $actorType, ?int $actorId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actorType, $actorId, $note) {
            // Lock so a double-submitted cancel cannot restore stock twice.
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $from = $order->statusEnum();

            if (!$from->canTransitionTo($to)) {
                throw new DomainException(__('orders.transition_refused', ['from' => $from->value, 'to' => $to->value]));
            }

            // An online order is prepared only once the provider has confirmed the payment.
            if ($to !== OrderStatus::Canceled && $order->requiresOnlinePayment() && !$order->paymentStatusEnum()->isSettledPayment()) {
                throw new DomainException(__('orders.awaiting_online_payment'));
            }

            // Dispatch (or hand-over without a shipment): the reserved units leave the warehouse.
            if ($to->isDispatched()) {
                $this->fulfilStock($order, $actorType === self::ACTOR_ADMIN ? $actorId : null);
            }

            if ($to === OrderStatus::Canceled) {
                $this->restoreStock($order, $actorType === self::ACTOR_ADMIN ? $actorId : null);
                $this->cancelOpenShipments($order);
                $this->reverseLoyaltyOnCancel($order, $actorType === self::ACTOR_ADMIN ? $actorId : null);
                app(PaymentService::class)->onOrderCanceled($order, $actorType, $actorId);
                // Promotion and coupon uses go back (flash sale units count from live orders only).
                app(PromotionLifecycle::class)->release($order);
                $order->canceled_at = now();
            }

            if ($to === OrderStatus::Confirmed) {
                $order->confirmed_at ??= now();
            }

            if ($to === OrderStatus::Completed) {
                $order->completed_at = now();
                $this->collectCashOnCompletion($order, $actorType, $actorId);
                app(PromotionLifecycle::class)->consume($order);
            }

            $order->status = $to->value;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'note' => $note,
            ]);

            return $order;
        }, 3); // deadlock retries (only when this is the outermost transaction)
    }

    /**
     * Completing a COD order whose cash was not recorded yet (pickup at the counter, or a delivery
     * marked complete by hand): the remaining amount is recorded as collected. Collection only:
     * settlement stays a separate step.
     */
    private function collectCashOnCompletion(Order $order, string $actorType, ?int $actorId): void
    {
        $payment = Payment::where('order_id', $order->id)->latest('id')->first();

        if (!$payment?->isCod() || !in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::PartiallyPaid], true)) {
            return;
        }

        $dueMinor = $payment->amountMinor() - Money::toMinor($payment->collected_amount);
        if ($dueMinor > 0) {
            app(PaymentService::class)->recordCodCollection($payment, $dueMinor, $order->address_id ? 'courier' : 'counter',
                $actorType, $actorType === self::ACTOR_ADMIN ? $actorId : null, 'Collected on completion');
        }
    }

    private function fulfilStock(Order $order, ?int $adminId): void
    {
        foreach ($order->items as $item) {
            $this->inventory->fulfil($item, ['admin_id' => $adminId]);
        }
    }

    /**
     * Canceling undoes the stock side of every line (gift lines included) exactly once:
     * reservations are released; units already dispatched come back through quarantine.
     */
    private function restoreStock(Order $order, ?int $adminId): void
    {
        foreach ($order->items as $item) {
            $this->inventory->releaseForCancellation($item, ['admin_id' => $adminId]);
        }
    }

    private function cancelOpenShipments(Order $order): void
    {
        Shipment::where('order_id', $order->id)->get()
            ->reject(fn (Shipment $shipment) => $shipment->status->isFinal())
            ->each(function (Shipment $shipment) use ($order) {
                ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'status' => ShipmentStatus::Canceled->value,
                    'source' => ShipmentEvent::SOURCE_SYSTEM,
                    'occurred_at' => now(),
                    'idempotency_key' => "order-canceled:{$order->id}:shipment:{$shipment->id}",
                    'applied' => true,
                    'note' => 'Order canceled',
                ]);
                $withdrawn = !$shipment->provider || app(\App\Modules\Delivery\Providers\ProviderRegistry::class)->for($shipment->provider)->cancelShipment($shipment->provider, $shipment);
                // A provider that could not withdraw it still has the parcel: a person must follow up.
                $shipment->update(['status' => ShipmentStatus::Canceled->value, 'needs_attention' => !$withdrawn]);
            });
    }

    /** Canceled orders never count for points; the ledger records that the earn is void. */
    private function reverseLoyaltyOnCancel(Order $order, ?int $adminId): void
    {
        $remaining = (int) $order->points_earned - (int) $order->pointReversals()->sum('points');

        if ($remaining <= 0) {
            return;
        }

        LoyaltyLedgerEntry::firstOrCreate(
            ['type' => LoyaltyLedgerEntry::REVERSE, 'order_id' => $order->id, 'reference_type' => 'order_cancellation', 'reference_id' => $order->id],
            ['user_id' => $order->user_id, 'points' => -$remaining, 'admin_id' => $adminId, 'occurred_at' => now()],
        );
    }
}
