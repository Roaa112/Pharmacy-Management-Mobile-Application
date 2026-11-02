<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Orders\Services\ReturnDecisionService;
use App\Modules\Payments\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Final audit: every illegal order transition is attempted on purpose and must fail without
 * changing stock, money or status.
 */
class OrderStateMachineAuditTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        Setting::current()->update(['customer_can_cancel_pending' => true]);
        Sanctum::actingAs($this->user);
    }

    private function codOrder(int $quantity = 1): Order
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $address = $this->makeAddress($this->user);
        $this->putInCart($this->user, $this->makeSize(price: 50, stock: 10), $quantity);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    private function move(Order $order, OrderStatus $to): Order
    {
        return app(OrderWorkflow::class)->transition($order->fresh(), $to, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
    }

    private function deliver(Order $order): void
    {
        app(OrderFulfilment::class)->record(Shipment::where('order_id', $order->id)->sole(), ShipmentStatus::Delivered, null,
            ShipmentEvent::SOURCE_WEBHOOK, null, now(), [], "audit:{$order->id}:delivered:".uniqid());
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('The illegal action was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------ customer cancellation race

    public function test_a_customer_cannot_cancel_an_order_that_was_dispatched_after_the_app_read_it(): void
    {
        $order = $this->codOrder();
        $this->move($order, OrderStatus::Confirmed);
        Order::whereKey($order->id)->update(['status' => 'pending']); // what the cancel request reads first

        // Between the customer's read and the lock, the warehouse dispatches the order.
        $dispatched = false;
        Order::retrieved(function (Order $retrieved) use ($order, &$dispatched) {
            if (!$dispatched && $retrieved->id === $order->id && $retrieved->status === 'pending') {
                $dispatched = true;
                Order::whereKey($order->id)->update(['status' => 'confirmed']);
                app(OrderWorkflow::class)->transition(Order::findOrFail($order->id), OrderStatus::OnDelivery, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
            }
        });

        $this->postJson("/api/user/orders/{$order->id}/cancel")->assertStatus(422)->assertJsonPath('code', 'cancel_not_allowed');

        $this->assertTrue($dispatched);
        $this->assertSame('on_delivery', $order->fresh()->status, 'the dispatched order is not canceled by the customer');
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    public function test_a_customer_can_still_cancel_a_pending_order(): void
    {
        $order = $this->codOrder();

        $this->postJson("/api/user/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('status', 'canceled');

        $this->assertSame([10, 'canceled'], [$order->items()->first()->size->fresh()->stock, $order->fresh()->payment_status]);
    }

    // ------------------------------------------------------------------ illegal transitions

    public function test_terminal_orders_never_move_again(): void
    {
        $completed = $this->codOrder();
        $this->deliver($completed);
        $this->assertSame('completed', $completed->fresh()->status);

        foreach ([OrderStatus::Canceled, OrderStatus::Pending, OrderStatus::OnDelivery, OrderStatus::Completed] as $to) {
            $this->assertRefused(fn () => $this->move($completed, $to));
        }
        $this->postJson("/api/user/orders/{$completed->id}/cancel")->assertStatus(422);

        $canceled = $this->codOrder();
        $this->move($canceled, OrderStatus::Canceled);
        foreach ([OrderStatus::Confirmed, OrderStatus::OnDelivery, OrderStatus::Completed, OrderStatus::Canceled] as $to) {
            $this->assertRefused(fn () => $this->move($canceled, $to));
        }

        $this->assertSame(['completed', 'paid', 'canceled', 'canceled'], [$completed->fresh()->status, $completed->fresh()->payment_status, $canceled->fresh()->status, $canceled->fresh()->payment_status]);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    public function test_backward_transitions_are_refused(): void
    {
        $order = $this->codOrder();
        $this->move($order, OrderStatus::Packed);

        foreach ([OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Picking, OrderStatus::Packed] as $to) {
            $this->assertRefused(fn () => $this->move($order, $to));
        }
        $this->assertSame('packed', $order->fresh()->status);
    }

    public function test_a_courier_delivering_a_canceled_order_changes_nothing(): void
    {
        $order = $this->codOrder();
        $this->move($order, OrderStatus::Canceled);

        $this->deliver($order);

        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertSame(['canceled', 'canceled', 0.0], [$order->fresh()->status, $payment->status->value, (float) $payment->collected_amount]);
        $this->assertSame(10, $order->items()->first()->size->fresh()->stock);
    }

    public function test_an_unpaid_order_cannot_be_refunded_or_returned(): void
    {
        $order = $this->codOrder();
        $payment = Payment::where('order_id', $order->id)->sole();

        $this->assertRefused(fn () => app(RefundService::class)->requestForPayment($payment, 1000, 'Try', $this->admin->id));
        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'Changed my mind', 'refund_amount' => 55])
            ->assertStatus(422)->assertJsonPath('code', 'order_not_delivered');

        $this->assertSame(0, Refund::count());
    }

    public function test_refunds_and_returns_cannot_exceed_what_was_paid_or_delivered(): void
    {
        $order = $this->codOrder(quantity: 2);
        $this->deliver($order);
        $line = $order->items()->first();

        $first = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'Damaged', 'refund_amount' => 50]);
        app(ReturnDecisionService::class)->accept($first->id, 50, [$line->id => 1], $this->admin->id);

        // The same return accepted twice: refused.
        $this->assertRefused(fn () => app(ReturnDecisionService::class)->accept($first->id, 50, [$line->id => 1], $this->admin->id));

        // A second return for more units than are left with the customer: refused.
        $second = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'Damaged', 'refund_amount' => 55]);
        $this->assertRefused(fn () => app(ReturnDecisionService::class)->accept($second->id, 50, [$line->id => 2], $this->admin->id));
        // Or for more money than is left of the order (105.00 paid, 50.00 approved already).
        $this->assertRefused(fn () => app(ReturnDecisionService::class)->accept($second->id, 56, [$line->id => 1], $this->admin->id));

        // A payment-level refund on top of the approved return cannot exceed what was received either.
        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertRefused(fn () => app(RefundService::class)->requestForPayment($payment, 5600, 'Extra', $this->admin->id));

        $this->assertSame(['pending', 1], [$second->fresh()->status, Refund::count()]);
        $this->assertSame(1, $line->size->fresh()->quarantined_stock);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    public function test_a_customer_cannot_cancel_a_return_the_pharmacy_accepted_meanwhile(): void
    {
        $order = $this->codOrder(quantity: 2);
        $this->deliver($order);
        $line = $order->items()->first();
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'Damaged', 'refund_amount' => 50]);

        // The admin accepts it between the customer's read and their write.
        $accepted = false;
        ReturnRequest::retrieved(function (ReturnRequest $retrieved) use ($return, $line, &$accepted) {
            if (!$accepted && $retrieved->id === $return->id && $retrieved->status === 'pending') {
                $accepted = true;
                app(ReturnDecisionService::class)->accept($return->id, 50, [$line->id => 1], $this->admin->id);
            }
        });

        $this->postJson("/api/user/returns/{$return->id}/cancel")->assertStatus(400);

        $this->assertTrue($accepted);
        $this->assertSame('accepted', $return->fresh()->status, 'the accepted return stays accepted (it counts toward what was returned and refunded)');

        // So the same unit cannot be returned and refunded a second time.
        $again = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'Damaged', 'refund_amount' => 55]);
        $this->assertRefused(fn () => app(ReturnDecisionService::class)->accept($again->id, 55, [$line->id => 2], $this->admin->id));
        $this->assertSame(1, $line->size->fresh()->quarantined_stock);
    }

    public function test_an_online_order_cannot_be_prepared_or_dispatched_before_it_is_paid(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 40));
        $order->forceFill(['payment_method' => Payment::PROVIDER_PAYMOB])->save();
        Payment::create(['order_id' => $order->id, 'provider' => Payment::PROVIDER_PAYMOB, 'internal_reference' => 'PAY-AUDIT1',
            'amount' => 40, 'status' => 'pending']);

        foreach ([OrderStatus::Confirmed, OrderStatus::Packed, OrderStatus::OnDelivery, OrderStatus::Completed] as $to) {
            $this->assertRefused(fn () => $this->move($order, $to));
        }
        $this->assertSame('pending', $order->fresh()->status);
    }
}
