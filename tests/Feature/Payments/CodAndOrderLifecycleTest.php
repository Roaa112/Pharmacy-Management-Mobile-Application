<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Admin;
use App\Models\CodSettlement;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Services\CodSettlementService;
use App\Modules\Payments\Services\PaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Cash on delivery and the complete order lifecycle: order, payment, stock and shipment statuses
 * move together through one path each, and COD collection is separate from settlement.
 */
class CodAndOrderLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    private function deliveredOrder(int $quantity = 2, int $stock = 10): Order
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $address = $this->makeAddress($this->user);
        $this->putInCart($this->user, $this->makeSize(price: 50, stock: $stock), $quantity);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    private function event(Shipment $shipment, string $status, string $key, ?string $note = null): void
    {
        app(OrderFulfilment::class)->record($shipment, ShipmentStatus::from($status), null, ShipmentEvent::SOURCE_WEBHOOK, null, now(), [], $key, $note);
    }

    private function balance(Order $order): InventoryBalance
    {
        return InventoryBalance::where('product_size_id', $order->items()->value('product_size_id'))->sole();
    }

    public function test_the_complete_cod_lifecycle(): void
    {
        $order = $this->deliveredOrder();
        $payment = $order->payments()->sole();
        $shipment = $order->shipments()->sole();

        // Placed: payment due on delivery, units reserved (still on the shelf), shipment pending.
        $this->assertSame(['pending', 'unpaid', 'pending'], [$order->status, $order->payment_status, $shipment->status->value]);
        $this->assertSame(['cod', 'pending', 'pending', 105.0], [$payment->provider, $payment->status->value, $payment->collection_status, (float) $payment->amount]);
        $this->assertSame([10, 2], [$this->balance($order)->on_hand, $this->balance($order)->reserved]);
        $this->assertEquals(105, $shipment->cod_amount);

        // Warehouse steps.
        $workflow = app(OrderWorkflow::class);
        foreach ([OrderStatus::Confirmed, OrderStatus::Picking, OrderStatus::Packed] as $step) {
            $workflow->transition($order, $step, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        }
        $this->getJson("/api/user/track-order/{$order->id}")->assertJsonPath('status', 'confirmed')->assertJsonPath('fulfillment_stage', 'packed');

        // Courier picks up: the order is dispatched and the units leave the warehouse.
        $this->event($shipment, 'picked_up', 'e1');
        $this->assertSame('on_delivery', $order->fresh()->status);
        $this->assertSame([8, 0], [$this->balance($order)->on_hand, $this->balance($order)->reserved]);
        $this->assertSame(2, StockMovement::where('type', StockMovement::TYPE_FULFILLMENT)->count());

        // Delivered: completed and cash collected by the courier — but not settled.
        $this->event($shipment, 'delivered', 'e2');
        $payment->refresh();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(['paid', 'collected', 'courier', 105.0], [$payment->status->value, $payment->collection_status, $payment->collected_via, (float) $payment->collected_amount]);
        $this->assertNull($payment->settled_at);
        $this->assertSame('paid', $order->fresh()->payment_status);

        // A repeated "delivered" changes nothing.
        $this->event($shipment, 'delivered', 'e3');
        $this->assertSame(1, $payment->history()->where('to_status', 'paid')->count());

        // Settlement: the courier hands over the cash.
        $settlement = app(CodSettlementService::class)->settle([$payment->id], 10500, $shipment->delivery_provider_id, 'R-1', null, $this->admin->id);
        $this->assertSame(0.0, (float) $settlement->difference);
        $this->assertNotNull($payment->fresh()->settled_at);
        $this->assertNotNull($shipment->fresh()->cod_settled_at);

        try {
            app(CodSettlementService::class)->settle([$payment->id], 10500, null, null, null, $this->admin->id);
            $this->fail('Settling twice must be refused.');
        } catch (DomainException) {
        }
        $this->assertSame(1, CodSettlement::count());
    }

    public function test_failed_delivery_keeps_the_cash_due_and_a_returned_shipment_comes_back_to_quarantine(): void
    {
        $order = $this->deliveredOrder();
        $shipment = $order->shipments()->sole();
        $this->event($shipment, 'picked_up', 'a');
        $this->event($shipment, 'failed', 'b', 'Customer not home');

        $payment = $order->payments()->sole();
        $this->assertSame(['pending', 'failed'], [$payment->status->value, $payment->collection_status]);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $this->event($shipment, 'returned', 'c');
        $balance = $this->balance($order);
        $this->assertSame([8, 2], [$balance->on_hand, $balance->quarantined], 'returned goods are not sellable until inspected');

        // Canceling afterwards neither restocks nor double-counts the returned units.
        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $balance->refresh();
        $this->assertSame([8, 2, 0], [$balance->on_hand, $balance->quarantined, $balance->reserved]);
        $this->assertSame('canceled', $payment->fresh()->status->value);
    }

    public function test_partial_cash_collection_and_settlement_shortfall(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 100, stock: 5), 1);
        $order = Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->json('order_id'));
        $payment = $order->payments()->sole();

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.payments.collect', $payment), ['amount' => '60', 'via' => 'counter'])->assertSessionHas('success');
        $this->assertSame(['partially_paid', 'partially_collected'], [$payment->fresh()->status->value, $payment->fresh()->collection_status]);
        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        // More than what is still due is refused.
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.payments.collect', $payment), ['amount' => '50', 'via' => 'counter'])->assertSessionHas('error');

        // Completing the order collects the remainder at the counter.
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame(['paid', 100.0], [$payment->fresh()->status->value, (float) $payment->fresh()->collected_amount]);

        // Only 90 reaches the safe: the 10 shortfall is recorded.
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.cod.settle'), ['payment_ids' => [$payment->id], 'received_amount' => '90'])->assertRedirect();
        $this->assertSame(-10.0, (float) CodSettlement::sole()->difference);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'cod.settle']);
    }

    public function test_invalid_order_transitions_are_refused(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 10, stock: 5), 1);
        $order = Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->json('order_id'));
        $workflow = app(OrderWorkflow::class);

        $workflow->transition($order, OrderStatus::Packed, OrderWorkflow::ACTOR_ADMIN, $this->admin->id); // steps may be skipped

        foreach ([OrderStatus::Picking, OrderStatus::Confirmed, OrderStatus::Pending] as $backwards) {
            try {
                $workflow->transition($order, $backwards, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
                $this->fail("packed -> {$backwards->value} must be refused");
            } catch (DomainException) {
            }
        }

        $workflow->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->expectException(DomainException::class);
        $workflow->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
    }

    public function test_customers_see_warehouse_steps_as_confirmed(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 10, stock: 5), 1);
        $order = Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->json('order_id'));
        app(OrderWorkflow::class)->transition($order, OrderStatus::Picking, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);

        $this->getJson("/api/user/order-details/{$order->id}")->assertJsonPath('status', 'confirmed')->assertJsonPath('fulfillment_stage', 'picking')
            ->assertJsonPath('status_meaning', __('orders.status_meaning.confirmed', [], 'ar'));
        $this->getJson('/api/user/orders/grouped-by-status')->assertJsonPath('data.confirmed.0.order_id', $order->id);
    }

    public function test_cod_reconciliation_and_payment_permissions(): void
    {
        $order = $this->deliveredOrder();
        $this->event($order->shipments()->sole(), 'delivered', 'd');

        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();
        $accountant = Admin::create(['name' => 'Acc', 'email' => 'acc@example.com', 'password' => 'secret-password']);
        $accountant->forceFill(['role' => 'accountant'])->save();

        $this->actingAs($agent, 'admin')->get(route('dashboard.cod.index'))->assertForbidden();
        $this->actingAs($agent, 'admin')->get(route('dashboard.payments.index'))->assertForbidden();
        $this->actingAs($accountant, 'admin')->get(route('dashboard.cod.index'))->assertOk()->assertSee($order->payments()->sole()->internal_reference);
        $this->actingAs($accountant, 'admin')->get(route('dashboard.payments.reports'))->assertOk()->assertSee('COD');

        $settlementPayment = $order->payments()->sole();
        $this->actingAs($accountant, 'admin')->post(route('dashboard.cod.settle'), ['payment_ids' => [$settlementPayment->id], 'received_amount' => '105'])
            ->assertRedirect(route('dashboard.cod.settlements.show', CodSettlement::sole()));
        $this->actingAs($accountant, 'admin')->get(route('dashboard.cod.settlements.show', CodSettlement::sole()))->assertOk()->assertSee($settlementPayment->internal_reference);
    }

    public function test_payment_history_is_immutable(): void
    {
        $this->putInCart($this->user, $this->makeSize(), 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated();
        $history = Payment::sole()->history()->first();

        $this->expectException(\LogicException::class);
        $history->update(['to_status' => 'paid']);
    }

    public function test_payment_service_refuses_illegal_payment_transitions(): void
    {
        $this->putInCart($this->user, $this->makeSize(), 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated();
        $payment = Payment::sole();

        $this->expectException(DomainException::class);
        app(PaymentService::class)->transition($payment, \App\Enums\PaymentStatus::Refunded, 'admin', $this->admin->id);
    }
}
