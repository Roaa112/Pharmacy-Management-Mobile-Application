<?php

namespace Tests\Feature\Delivery;

use App\Enums\ShipmentStatus;
use App\Models\Admin;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\ProviderStatusMap;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Modules\Delivery\Services\OrderFulfilment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class ShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** A cash order delivered to an address, placed through checkout. */
    private function placeOrder(): Order
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $size = $this->makeSize(price: 20, stock: 10);
        $this->putInCart($this->user, $size, 2);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => $this->makeAddress($this->user)->id, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    private function event(Shipment $shipment, string $status, string $key)
    {
        return app(OrderFulfilment::class)->record($shipment, ShipmentStatus::from($status), null, ShipmentEvent::SOURCE_ADMIN, null, now(), [], $key);
    }

    public function test_shipment_events_drive_the_order_through_one_path(): void
    {
        $order = $this->placeOrder();
        $shipment = $order->shipments()->first();

        $this->event($shipment, 'assigned', 'k1');
        $this->assertSame('pending', $order->fresh()->status);

        $this->event($shipment, 'picked_up', 'k2');
        $this->assertSame('on_delivery', $order->fresh()->status);
        $this->assertNotNull($shipment->fresh()->picked_up_at);

        $this->event($shipment, 'delivered', 'k3');
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status, 'cash is collected on delivery');
        $this->assertNotNull($order->completed_at);
        $this->assertNotNull($shipment->fresh()->cod_collected_at);

        // History: checkout, then the two moves made by the shipment.
        $this->assertSame(['pending', 'on_delivery', 'completed'], $order->statusHistory()->pluck('to_status')->all());
    }

    public function test_events_are_idempotent_and_a_final_shipment_does_not_move(): void
    {
        $shipment = $this->placeOrder()->shipments()->first();

        [, $first] = $this->event($shipment, 'delivered', 'same-key');
        [, $again] = $this->event($shipment, 'delivered', 'same-key');
        $this->assertTrue($first);
        $this->assertFalse($again);
        $this->assertSame(1, ShipmentEvent::where('idempotency_key', 'same-key')->count());

        $this->event($shipment, 'in_transit', 'late');
        $this->assertSame('delivered', $shipment->fresh()->status->value);
    }

    public function test_failed_delivery_flags_the_shipment_and_leaves_the_order_for_a_person(): void
    {
        $order = $this->placeOrder();
        $shipment = $order->shipments()->first();
        $this->event($shipment, 'picked_up', 'a');

        app(OrderFulfilment::class)->record($shipment, ShipmentStatus::Failed, null, ShipmentEvent::SOURCE_ADMIN, null, now(), [], 'b', 'Nobody home');

        $shipment->refresh();
        $this->assertTrue($shipment->needs_attention);
        $this->assertSame(1, $shipment->attempts);
        $this->assertSame('Nobody home', $shipment->failure_reason);
        $this->assertSame('on_delivery', $order->fresh()->status);
    }

    public function test_canceling_the_order_cancels_its_open_shipment_and_restores_stock_once(): void
    {
        $order = $this->placeOrder();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $sizeId = $order->items->first()->product_size_id;

        $this->actingAs($admin, 'admin')->put(route('dashboard.orders.updateStatus', $order), ['status' => 'canceled'])->assertRedirect();
        $this->actingAs($admin, 'admin')->put(route('dashboard.orders.updateStatus', $order), ['status' => 'canceled'])->assertRedirect();

        $this->assertSame('canceled', $order->shipments()->first()->status->value);
        $this->assertSame(10, \App\Models\ProductSize::find($sizeId)->stock);
        $this->assertSame(['pending', 'canceled'], $order->statusHistory()->pluck('to_status')->all());
        $this->assertSame('admin', $order->statusHistory()->get()->last()->actor_type);
    }

    public function test_dashboard_assigns_a_provider_records_events_and_settles_cod(): void
    {
        $order = $this->placeOrder();
        $shipment = $order->shipments()->first();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $courier = DeliveryProvider::create(['name' => 'Courier', 'code' => 'courier', 'type' => 'external_manual', 'adapter' => 'manual', 'tracking_url_template' => 'https://t.example/{tracking}']);

        $this->actingAs($admin, 'admin')->post(route('dashboard.delivery.shipments.assign', $shipment), ['delivery_provider_id' => $courier->id, 'tracking_number' => 'TRK 1'])->assertRedirect();
        $shipment->refresh();
        $this->assertSame('assigned', $shipment->status->value);
        $this->assertSame('https://t.example/TRK%201', $shipment->tracking_url);

        $this->actingAs($admin, 'admin')->post(route('dashboard.delivery.shipments.events', $shipment), ['status' => 'delivered'])->assertRedirect();
        $this->assertSame('completed', $order->fresh()->status);

        $this->actingAs($admin, 'admin')->post(route('dashboard.delivery.shipments.settleCod', $shipment))->assertRedirect();
        $this->assertNotNull($shipment->fresh()->cod_settled_at);

        // The customer sees the delivery in the existing tracking endpoint (additive field).
        Sanctum::actingAs($this->user);
        $this->getJson("/api/user/track-order/{$order->id}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('status_meaning', 'تم لاتوصيل بنجاح')
            ->assertJsonPath('delivery.status', 'delivered')
            ->assertJsonPath('delivery.tracking_number', 'TRK 1');
    }

    public function test_shipment_events_cannot_be_rewritten(): void
    {
        $this->placeOrder();
        $event = ShipmentEvent::firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['status' => 'delivered']);
    }

    public function test_provider_status_mapping_is_case_insensitive_and_unknown_is_null(): void
    {
        $provider = DeliveryProvider::create(['name' => 'X', 'code' => 'x', 'type' => 'external_api', 'adapter' => 'signed_webhook']);
        ProviderStatusMap::create(['delivery_provider_id' => $provider->id, 'external_status' => 'OUT_FOR_DELIVERY', 'internal_status' => 'in_transit']);
        $fulfilment = app(OrderFulfilment::class);

        $this->assertSame(ShipmentStatus::InTransit, $fulfilment->mapStatus($provider, 'out_for_delivery'));
        $this->assertSame(ShipmentStatus::Delivered, $fulfilment->mapStatus($provider, 'delivered'));
        $this->assertNull($fulfilment->mapStatus($provider, 'WAITING_AT_HUB'));
    }

}
