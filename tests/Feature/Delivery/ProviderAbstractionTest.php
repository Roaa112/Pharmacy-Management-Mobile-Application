<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Modules\Delivery\Providers\DeliveryProviderAdapter;
use App\Modules\Delivery\Providers\ManualAdapter;
use App\Modules\Delivery\Providers\WebhookUpdate;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Orders\Services\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * The provider interface (create, cancel, fetch status, parse webhook) drives shipments without
 * the order module knowing which company carries them.
 */
class ProviderAbstractionTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private function order(array $rate = []): Order
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->makeDeliveryPrice('Cairo', 5, $rate);
        $address = $this->makeAddress($user);
        $this->putInCart($user, $this->makeSize(stock: 5), 1);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    /** An adapter standing in for a courier with an API. */
    private function fakeCourier(bool $cancels, ?string $status): object
    {
        $fake = new class($cancels, $status) implements DeliveryProviderAdapter {
            public array $canceled = [];

            public function __construct(private bool $cancels, private ?string $status)
            {
            }

            public function createShipment(DeliveryProvider $provider, Shipment $shipment): array
            {
                return ['tracking_number' => 'TRK-'.$shipment->id, 'external_reference' => 'EXT-'.$shipment->id];
            }

            public function cancelShipment(DeliveryProvider $provider, Shipment $shipment): bool
            {
                $this->canceled[] = $shipment->id;

                return $this->cancels;
            }

            public function fetchStatus(DeliveryProvider $provider, Shipment $shipment): ?WebhookUpdate
            {
                return $this->status ? new WebhookUpdate('EXT-'.$shipment->id, null, $this->status, 'poll-'.$this->status, Carbon::now()) : null;
            }

            public function verifyWebhook(DeliveryProvider $provider, Request $request): bool
            {
                return false;
            }

            public function parseWebhook(DeliveryProvider $provider, Request $request): array
            {
                return [];
            }
        };
        $this->app->instance(ManualAdapter::class, $fake);

        return $fake;
    }

    public function test_the_estimated_delivery_date_comes_from_the_rate(): void
    {
        $order = $this->order(['eta_min_days' => 1, 'eta_max_days' => 3]);

        $this->assertSame(now()->addDays(3)->toDateString(), $order->shipments()->sole()->estimated_delivery_at->toDateString());
        $this->getJson("/api/user/track-order/{$order->id}")->assertJsonPath('delivery.estimated_delivery_at', now()->addDays(3)->toDateString());
    }

    public function test_assigning_uses_the_adapter_and_polling_records_events_through_the_same_path(): void
    {
        $order = $this->order();
        $shipment = $order->shipments()->sole();
        $fake = $this->fakeCourier(true, 'delivered');
        $fulfilment = app(OrderFulfilment::class);

        $shipment = $fulfilment->assign($shipment, DeliveryProvider::default(), null, null, null);
        $this->assertSame(['TRK-'.$shipment->id, 'EXT-'.$shipment->id], [$shipment->tracking_number, $shipment->external_reference]);

        $this->assertTrue($fulfilment->poll($shipment));
        $this->assertFalse($fulfilment->poll($shipment->fresh()), 'the same polled event is recorded once');
        $this->assertSame('delivered', $shipment->fresh()->status->value);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status, 'cash collected on delivery');
        $this->assertSame([], $fake->canceled);
    }

    public function test_canceling_the_order_withdraws_the_shipment_and_a_refusal_needs_attention(): void
    {
        $order = $this->order();
        $fake = $this->fakeCourier(false, null);

        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_SYSTEM);

        $shipment = $order->shipments()->sole();
        $this->assertSame([$shipment->id], $fake->canceled);
        $this->assertSame('canceled', $shipment->status->value);
        $this->assertTrue($shipment->needs_attention, 'the courier still has the parcel');
    }

    public function test_a_push_only_provider_returns_no_status_to_poll(): void
    {
        $shipment = $this->order()->shipments()->sole();

        $this->assertFalse(app(OrderFulfilment::class)->poll($shipment));
        $this->assertSame('pending', $shipment->fresh()->status->value);
    }
}
