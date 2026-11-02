<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Country;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class OrderPoliciesTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function checkout(): Order
    {
        $size = $this->makeSize(price: 30, stock: 5);
        $this->putInCart($this->user, $size, 2);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    public function test_customer_cancellation_follows_the_store_policy(): void
    {
        $order = $this->checkout();

        // Off by default (decision D4): only admins cancel.
        $this->postJson("/api/user/orders/{$order->id}/cancel")->assertStatus(422)->assertJsonPath('code', 'cancel_not_allowed');
        $this->getJson("/api/user/order-details/{$order->id}")->assertJsonPath('can_cancel', false);

        Setting::current()->update(['customer_can_cancel_pending' => true]);
        $this->getJson("/api/user/order-details/{$order->id}")->assertJsonPath('can_cancel', true);
        $this->postJson("/api/user/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('status', 'canceled');

        $this->assertSame(5, ProductSize::find($order->items->first()->product_size_id)->stock);
        $this->assertSame('customer', $order->statusHistory()->get()->last()->actor_type);

        // Not twice, not someone else's, not once it moved on.
        $this->postJson("/api/user/orders/{$order->id}/cancel")->assertStatus(422);
        $confirmed = $this->checkout();
        $confirmed->forceFill(['status' => 'confirmed'])->save();
        $this->postJson("/api/user/orders/{$confirmed->id}/cancel")->assertStatus(422);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/user/orders/{$confirmed->id}/cancel")->assertNotFound();
    }

    /** The store currently operates in Egypt only and uses EGP. */
    public function test_orders_are_recorded_in_egypt_and_egp(): void
    {
        $this->assertSame(['EG'], Country::pluck('iso2')->all());
        $this->assertSame('EGP', config('pharmacy.currency'));
        $this->assertSame('Africa/Cairo', config('pharmacy.timezone'));

        $order = $this->checkout();
        $this->assertSame('EGP', $order->currency_code);
        $this->assertSame(Country::where('iso2', 'EG')->value('id'), $order->country_id);
        $this->assertSame('EGP', $order->payments()->sole()->currency, 'the payment carries the order currency');

        // Cash on delivery is offered without any country setup.
        $this->getJson('/api/v1/user/payment-methods')->assertOk()->assertJsonFragment(['code' => 'cash']);
    }

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $this->checkout();

        $this->expectException(QueryException::class);
        $this->user->delete();
    }

    public function test_return_window_only_applies_when_configured(): void
    {
        $order = $this->checkout();
        $request = fn () => $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => 10]);

        // Nothing was dispatched yet: nothing can come back (cancel instead), window or not.
        $request()->assertStatus(422)->assertJsonPath('code', 'order_not_delivered');

        // No window configured: a dispatched order can be returned, as before.
        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition($order, \App\Enums\OrderStatus::OnDelivery, 'system');
        $request()->assertCreated();
        ReturnRequest::query()->delete();

        Setting::current()->update(['return_window_days' => 7]);
        $request()->assertStatus(422)->assertJsonPath('code', 'order_not_completed');

        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition($order->fresh(), \App\Enums\OrderStatus::Completed, 'system');
        $order->forceFill(['completed_at' => now()->subDays(3)])->save();
        $request()->assertCreated();
        ReturnRequest::query()->delete();

        $order->forceFill(['completed_at' => now()->subDays(8)])->save();
        $request()->assertStatus(422)->assertJsonPath('code', 'return_window_closed');
    }

    public function test_display_id_is_plain_text_everywhere(): void
    {
        $order = $this->checkout();
        ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'status' => 'accepted']);

        $this->assertSame("طلب #{$order->id}", $order->fresh()->display_id);
    }

    /** Receipt verification remains possible for historical ZainCash orders placed before the retirement. */
    public function test_admin_payment_verification_is_recorded_and_needs_the_payments_permission(): void
    {
        // A legacy manual-transfer order (placed before ZainCash was retired) with a receipt waiting.
        $order = $this->checkout();
        $payment = $order->payments()->sole();
        $payment->forceFill(['provider' => 'manual_transfer', 'status' => 'awaiting_verification', 'collection_status' => null])->save();

        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();
        $accountant = Admin::create(['name' => 'Acc', 'email' => 'acc@example.com', 'password' => 'secret-password']);
        $accountant->forceFill(['role' => 'accountant'])->save();

        $this->actingAs($agent, 'admin')->patch(route('dashboard.orders.paymentStatus', $order), ['payment_status' => 'paid'])->assertForbidden();
        $this->actingAs($accountant, 'admin')->patch(route('dashboard.orders.paymentStatus', $order), ['payment_status' => 'paid'])->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($accountant->id, $order->payment_verified_by);
        $this->assertNotNull($order->payment_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'order.payment_status', 'subject_id' => $order->id, 'admin_id' => $accountant->id]);
        $this->assertDatabaseHas('payment_status_histories', ['payment_id' => $payment->id, 'from_status' => 'awaiting_verification', 'to_status' => 'paid', 'actor_type' => 'admin', 'actor_id' => $accountant->id]);

        // A COD order cannot be "marked paid" from this control (cash is collected, not declared).
        $cod = $this->checkout();
        $this->actingAs($accountant, 'admin')->from('/dashboard/orders')->patch(route('dashboard.orders.paymentStatus', $cod), ['payment_status' => 'paid'])->assertSessionHas('error');
        $this->assertSame('unpaid', $cod->fresh()->payment_status);
    }

    public function test_net_revenue_subtracts_partial_refunds_instead_of_dropping_the_order(): void
    {
        $size = $this->makeSize(price: 100);
        $a = $this->makeOrder($this->user, $size, status: 'completed');
        $this->makeOrder($this->user, $size, status: 'completed');
        $this->makeOrder($this->user, $size, status: 'pending');
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $a->id, 'reason' => 'x', 'refund_amount' => 30]);
        $return->forceFill(['status' => 'accepted', 'approved_amount' => 25])->save();

        // Approved but not paid back yet: revenue is unchanged (money only leaves with a succeeded refund).
        $this->assertSame(200.0, \App\Modules\Reports\Revenue::net(Order::query()));

        \App\Models\Refund::create(['reference' => 'RF-T1', 'order_id' => $a->id, 'return_request_id' => $return->id, 'amount' => 25,
            'method' => 'cash', 'status' => 'succeeded']);
        $this->assertSame(175.0, \App\Modules\Reports\Revenue::net(Order::query()));

        // A canceled refund gives nothing back.
        \App\Models\Refund::create(['reference' => 'RF-T2', 'order_id' => $a->id, 'amount' => 50, 'method' => 'cash', 'status' => 'canceled']);
        $this->assertSame(175.0, \App\Modules\Reports\Revenue::net(Order::query()));
    }

    public function test_best_selling_ignores_canceled_orders(): void
    {
        $popular = $this->makeSize();
        $canceled = $this->makeSize();
        $this->makeOrder($this->user, $popular, quantity: 2, status: 'completed');
        $this->makeOrder($this->user, $canceled, quantity: 9, status: 'canceled');

        $rows = $this->getJson('/api/v1/user/Best-selling-products')->assertOk()->json('data');
        $this->assertSame([2], array_column($rows, 'total_sold'));
    }

    public function test_listing_limit_is_capped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeSize();
        }

        $this->getJson('/api/v1/user/products?limit=100000')->assertOk();
        $this->assertSame('100', request()->query('limit'));
    }
}
