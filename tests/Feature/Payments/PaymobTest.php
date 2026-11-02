<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\StockReservation;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Paymob integration against faked HTTP. The credentials are test placeholders; no real
 * transaction is created or simulated outside the fake.
 */
class PaymobTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Every Paymob call must hit a fake: an unmatched request would reach the real API.
        Http::preventStrayRequests();
        $this->configurePaymob();
        PaymentMethod::where('code', 'paymob')->update(['is_active' => true]);
        $this->user = User::factory()->create(['email' => 'buyer@example.com', 'phone' => '01000000000']);
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    private function configurePaymob(array $overrides = []): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), [
            'environment' => 'sandbox',
            'flow' => 'intention',
            'base_url' => 'https://accept.paymob.com',
            'checkout_url' => 'https://eg.checkout.paymob.com/',
            'verify_with_api' => false,
            'currency' => null,
            'amount_factor' => 1,
            'payment_ttl_minutes' => 60,
            'return_url' => 'elkawther://payment-result',
            'sandbox' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret', 'public_key' => 'test-public',
                'hmac_secret' => 'test-hmac', 'integration_ids' => '111,222', 'iframe_id' => '333'],
            'production' => ['api_key' => null, 'secret_key' => null, 'public_key' => null, 'hmac_secret' => null, 'integration_ids' => null, 'iframe_id' => null],
        ], $overrides)]);
    }

    private function fakePaymob(array $extra = []): void
    {
        Http::fake($extra + [
            '*/v1/intention/*' => Http::response(['id' => 'pi_test_1', 'client_secret' => 'csk_test_1', 'intention_order_id' => 555], 201),
            '*/api/auth/tokens' => Http::response(['token' => 'auth-token']),
        ]);
    }

    private function checkoutWithPaymob(int $stock = 5): Order
    {
        $this->putInCart($this->user, $size = $this->makeSize(price: 100, stock: $stock), 1);
        $response = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'paymob'])->assertCreated();
        $this->assertSame($size->id, Order::findOrFail($response->json('order_id'))->items()->value('product_size_id'));

        return Order::findOrFail($response->json('order_id'));
    }

    /** A Paymob "transaction processed" object, as the webhook delivers it. */
    private function transaction(Payment $payment, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 9001, 'amount_cents' => $payment->amountMinor(), 'created_at' => '2026-11-01T10:00:00.000000',
            'currency' => 'EGP', 'error_occured' => false, 'has_parent_transaction' => false, 'integration_id' => 111,
            'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 555, 'merchant_order_id' => $payment->internal_reference],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ], $overrides);
    }

    private function webhook(array $transaction, ?string $hmac = null)
    {
        $hmac ??= app(PaymobGateway::class)->signature($transaction);

        return $this->postJson('/api/webhooks/payments/paymob?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $transaction]);
    }

    // ------------------------------------------------------------------ configuration

    public function test_without_credentials_paymob_is_not_offered_and_cannot_be_activated(): void
    {
        config(['services.paymob.sandbox' => array_fill_keys(['api_key', 'secret_key', 'public_key', 'hmac_secret', 'integration_ids', 'iframe_id'], null)]);

        $this->assertNotEmpty(app(PaymobGateway::class)->missingConfiguration());
        $codes = collect($this->getJson('/api/v1/user/payment-methods')->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('paymob'));

        $this->putInCart($this->user, $this->makeSize(), 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'paymob'])->assertStatus(422);
        $this->assertSame(0, Order::count());

        PaymentMethod::where('code', 'paymob')->update(['is_active' => false]);
        $method = PaymentMethod::where('code', 'paymob')->sole();
        $this->actingAs($this->admin, 'admin')->from('/dashboard/payment-methods')
            ->put(route('dashboard.payment_methods.update', $method), ['name_en' => 'Online', 'name_ar' => 'إلكتروني', 'is_active' => 1])
            ->assertSessionHas('error');
        $this->assertFalse($method->fresh()->is_active);
    }

    public function test_sandbox_and_production_use_separate_credentials(): void
    {
        $gateway = app(PaymobGateway::class);
        $this->assertSame([], $gateway->missingConfiguration());

        config(['services.paymob.environment' => 'production']);
        $this->assertSame('production', $gateway->environment());
        $this->assertContains('production.hmac_secret', $gateway->missingConfiguration(), 'production does not fall back to sandbox keys');

        config(['services.paymob.production' => ['secret_key' => 'live-secret', 'public_key' => 'live-public', 'hmac_secret' => 'live-hmac', 'integration_ids' => '9', 'api_key' => 'live-key']]);
        $tx = ['id' => 1, 'success' => true];
        $live = $gateway->signature($tx);
        config(['services.paymob.environment' => 'sandbox']);
        $this->assertNotSame($live, $gateway->signature($tx), 'each environment signs with its own HMAC secret');
    }

    // ------------------------------------------------------------------ initiation

    public function test_checkout_creates_a_pending_payment_and_returns_the_checkout_url_without_secrets(): void
    {
        $this->fakePaymob();
        $this->putInCart($this->user, $this->makeSize(price: 100, stock: 5), 1);

        $response = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'paymob'])->assertCreated()
            ->assertJsonPath('payment_status', 'pending')
            ->assertJsonPath('payment.status', 'pending');

        $payment = Payment::sole();
        $this->assertSame(['paymob', 'pending', '555'], [$payment->provider, $payment->status->value, $payment->provider_order_id]);
        $this->assertStringStartsWith('https://eg.checkout.paymob.com/?publicKey=test-public&clientSecret=csk_test_1', $response->json('payment.checkout_url'));
        $this->assertNotNull($payment->expires_at);

        foreach (['test-secret', 'test-hmac', 'test-api-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/intention/')
            && $request->header('Authorization')[0] === 'Token test-secret'
            && $request['amount'] === 10000
            && $request['currency'] === 'EGP'
            && $request['special_reference'] === $payment->internal_reference
            && $request['payment_methods'] === [111, 222]
            && str_ends_with($request['notification_url'], '/api/webhooks/payments/paymob'));

        // Stock is held, not sold, until the payment is confirmed and the order dispatched.
        $this->assertSame(1, (int) StockReservation::where('status', 'active')->sum('quantity'));
    }

    public function test_legacy_iframe_flow(): void
    {
        $this->configurePaymob(['flow' => 'legacy']);
        Http::fake([
            '*/api/auth/tokens' => Http::response(['token' => 'auth-token']),
            '*/api/ecommerce/orders' => Http::response(['id' => 777]),
            '*/api/acceptance/payment_keys' => Http::response(['token' => 'pk_1']),
        ]);

        $order = $this->checkoutWithPaymob();
        $this->assertSame('777', $order->payments()->sole()->provider_order_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/ecommerce/orders') && $r['merchant_order_id'] === $order->payments()->sole()->internal_reference);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/acceptance/payment_keys') && $r['integration_id'] === 111);
    }

    public function test_an_unreachable_provider_fails_the_attempt_and_the_customer_can_retry(): void
    {
        $this->fakePaymob(['*/v1/intention/*' => Http::response([], 500)]);
        $this->putInCart($this->user, $this->makeSize(price: 100, stock: 5), 1);

        $response = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'paymob'])->assertCreated();
        $this->assertNull($response->json('payment.checkout_url'));
        $this->assertNotNull($response->json('payment.error'));
        $order = Order::sole();
        $this->assertSame(['pending', 'failed'], [$order->status, $order->payment_status]);
        $this->assertSame(PaymentEvent::RESULT_FAILED, PaymentEvent::where('event_type', 'initiation_failed')->sole()->result);

        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakePaymob();
        $retry = $this->postJson("/api/user/orders/{$order->id}/pay")->assertOk();
        $this->assertNotNull($retry->json('payment.checkout_url'));
        $this->assertSame([1, 2], Payment::orderBy('id')->pluck('attempt')->all());
        $this->assertNotSame(...Payment::orderBy('id')->pluck('internal_reference')->all());

        // Another customer cannot pay (or see) this order.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/user/orders/{$order->id}/pay")->assertNotFound();
    }

    // ------------------------------------------------------------------ webhooks

    public function test_a_verified_success_marks_the_payment_paid_once(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();
        $tx = $this->transaction($payment);

        $this->webhook($tx)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhook($tx)->assertOk()->assertJsonPath('status', 'duplicate');

        $payment->refresh();
        $this->assertSame(['paid', '9001'], [$payment->status->value, $payment->provider_transaction_id]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, $payment->history()->where('to_status', 'paid')->count());
        $this->assertSame(1, PaymentEvent::where('provider_event_id', '9001')->count());

        // Only now can the order be prepared.
        app(OrderWorkflow::class)->transition($order, OrderStatus::Confirmed, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->getJson("/api/user/orders/{$order->id}/payment")->assertOk()->assertJsonPath('payment.status', 'paid');
    }

    public function test_an_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();

        $this->webhook($this->transaction($payment), str_repeat('a', 128))->assertStatus(400);
        $this->webhook($this->transaction($payment), '')->assertStatus(400);

        // A forged body signed for different values does not pass either.
        $signed = $this->transaction($payment, ['amount_cents' => 1]);
        $this->webhook($this->transaction($payment), app(PaymobGateway::class)->signature($signed))->assertStatus(400);

        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertSame(3, PaymentEvent::where('result', 'rejected')->where('signature_valid', false)->count());
    }

    public function test_amount_and_currency_must_match_the_payment(): void
    {
        $this->fakePaymob();
        $payment = $this->checkoutWithPaymob()->payments()->sole();

        $this->webhook($this->transaction($payment, ['amount_cents' => 100]))->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertStringContainsString('Amount mismatch', $payment->fresh()->metadata['needs_review']);

        // Orders are in EGP; a transaction in another currency is rejected.
        $this->webhook($this->transaction($payment, ['id' => 9002, 'currency' => 'USD']))->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_declined_payment_fails_and_the_order_cannot_be_confirmed(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();

        $this->webhook($this->transaction($payment, ['success' => false, 'data' => ['message' => 'Insufficient funds']]))->assertOk();

        $this->assertSame(['failed', 'Insufficient funds'], [$payment->fresh()->status->value, $payment->fresh()->failure_reason]);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->expectException(DomainException::class);
        app(OrderWorkflow::class)->transition($order, OrderStatus::Confirmed, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
    }

    public function test_pending_then_success_and_an_older_failure_cannot_undo_a_payment(): void
    {
        $this->fakePaymob();
        $payment = $this->checkoutWithPaymob()->payments()->sole();

        $this->webhook($this->transaction($payment, ['pending' => true, 'success' => false]))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status->value);

        $this->webhook($this->transaction($payment))->assertOk();
        $this->webhook($this->transaction($payment, ['success' => false]))->assertOk(); // stale "failed" replayed
        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_server_side_verification_overrides_a_validly_signed_message(): void
    {
        $this->configurePaymob(['verify_with_api' => true]);
        $this->fakePaymob();
        $payment = $this->checkoutWithPaymob()->payments()->sole();

        // Paymob's API says this transaction failed: the (signed) success message is not trusted.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/auth/tokens' => Http::response(['token' => 'auth-token']),
            '*/api/acceptance/transactions/9001' => Http::response($this->transaction($payment, ['success' => false])),
        ]);
        $this->webhook($this->transaction($payment))->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('pending', $payment->fresh()->status->value);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/acceptance/transactions/9001') && $r->header('Authorization')[0] === 'Bearer auth-token');
    }

    public function test_a_webhook_for_an_unknown_payment_is_stored_and_ignored(): void
    {
        $this->webhook($this->transaction(new Payment(['amount' => 1, 'internal_reference' => 'PAY-UNKNOWN', 'currency' => 'EGP'])))->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame('No matching payment', PaymentEvent::sole()->error);
    }

    public function test_the_customer_redirect_is_verified_and_sent_back_to_the_app(): void
    {
        $this->fakePaymob();
        $payment = $this->checkoutWithPaymob()->payments()->sole();
        $tx = $this->transaction($payment);
        $flat = collect(\Illuminate\Support\Arr::dot($tx))->map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v)->all();
        $flat['order'] = $tx['order']['id'];
        $flat['merchant_order_id'] = $payment->internal_reference;
        unset($flat['order.id'], $flat['order.merchant_order_id']);
        $flat['hmac'] = app(PaymobGateway::class)->signature($tx);

        $this->get('/payments/paymob/return?'.http_build_query($flat))
            ->assertRedirect('elkawther://payment-result?status=success&reference='.$payment->internal_reference.'&order_id='.$payment->order_id);
        $this->assertSame('paid', $payment->fresh()->status->value);

        // Tampered redirect: reported as failed, nothing applied.
        $flat['amount_cents'] = 1;
        $this->get('/payments/paymob/return?'.http_build_query($flat))->assertRedirect('elkawther://payment-result?status=failed');
    }

    // ------------------------------------------------------------------ refunds, expiry, admin

    public function test_canceling_a_paid_order_queues_a_gateway_refund_processed_through_paymob(): void
    {
        $this->fakePaymob(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9100, 'success' => true, 'pending' => false])]);
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();
        $this->webhook($this->transaction($payment))->assertOk();

        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $refund = Refund::sole();
        $this->assertSame(['pending', 'gateway', 100.0], [$refund->status, $refund->method, (float) $refund->amount]);
        $this->assertSame(0, StockReservation::where('status', 'active')->count(), 'stock released');

        // Only admins with the refunds permission.
        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();
        $this->actingAs($agent, 'admin')->post(route('dashboard.refunds.process', $refund))->assertForbidden();

        // Separation of duties: the admin who canceled the order (and so requested the refund) cannot pay it out.
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.refunds.process', $refund))->assertSessionHas('error');
        $cashier = Admin::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'secret-password']);
        $this->actingAs($cashier, 'admin')->post(route('dashboard.refunds.process', $refund))->assertSessionHas('success');
        $this->assertSame(['succeeded', '9100'], [$refund->fresh()->status, $refund->fresh()->provider_refund_id]);
        $this->assertSame('refunded', $payment->fresh()->status->value);
        $this->assertSame(['refunded', 'full'], [$order->fresh()->payment_status, $order->fresh()->refund_status]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/void_refund/refund') && $r['transaction_id'] === '9001' && $r['amount_cents'] === 10000
            && $r->header('Authorization')[0] === 'Token test-secret');

        // A second processing attempt changes nothing.
        $this->actingAs($cashier, 'admin')->post(route('dashboard.refunds.process', $refund))->assertSessionHas('error');
    }

    public function test_a_refund_made_in_the_paymob_dashboard_is_recorded_from_its_callback(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();
        $this->webhook($this->transaction($payment))->assertOk();

        $refundTx = $this->transaction($payment, ['id' => 9200, 'amount_cents' => 4000, 'has_parent_transaction' => true, 'parent_transaction' => 9001, 'is_refund' => true]);
        $this->webhook($refundTx)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhook($refundTx)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(['succeeded', 40.0], [Refund::sole()->status, (float) Refund::sole()->amount]);
        $this->assertSame('partially_refunded', $payment->fresh()->status->value);
        $this->assertSame('partial', $order->fresh()->refund_status);
    }

    public function test_an_unpaid_online_order_expires_and_releases_its_stock(): void
    {
        // Paymob confirms the order has no transaction before anything expires.
        $this->fakePaymob(['*/api/ecommerce/orders/transaction_inquiry' => Http::response(['message' => 'not found'], 404)]);
        $order = $this->checkoutWithPaymob(stock: 3);
        $size = $order->items()->first()->size;
        $this->assertSame(2, $size->fresh()->stock);

        $this->travel(61)->minutes();
        $this->artisan('payments:expire-stale')->assertSuccessful();

        $this->assertSame('expired', $order->payments()->sole()->status->value);
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(3, $size->fresh()->stock);
        $this->assertSame(0, StockReservation::where('status', 'active')->count());
    }

    public function test_a_late_verified_payment_on_a_canceled_order_is_recorded_and_refunded(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();
        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame('canceled', $payment->fresh()->status->value);

        $this->webhook($this->transaction($payment))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame(['pending', 100.0], [Refund::sole()->status, (float) Refund::sole()->amount]);
    }

    public function test_admins_cannot_mark_an_online_payment_paid(): void
    {
        $this->fakePaymob();
        $order = $this->checkoutWithPaymob();

        $this->actingAs($this->admin, 'admin')->from('/dashboard/orders')
            ->patch(route('dashboard.orders.paymentStatus', $order), ['payment_status' => 'paid'])->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->expectException(DomainException::class);
        app(PaymentService::class)->verifyManualReceipt($order->payments()->sole(), true, $this->admin->id);
    }

    public function test_dashboard_payment_pages_render(): void
    {
        $this->fakePaymob(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9100, 'success' => true])]);
        $order = $this->checkoutWithPaymob();
        $payment = $order->payments()->sole();
        $this->webhook($this->transaction($payment))->assertOk();
        $this->webhook($this->transaction($payment), 'bad')->assertStatus(400);
        $refund = app(RefundService::class)->requestForPayment($payment->fresh(), 1000, 'Goodwill', $this->admin->id);

        $as = $this->actingAs($this->admin, 'admin');
        $as->get(route('dashboard.payments.index'))->assertOk()->assertSee($payment->internal_reference)->assertSee('Configured');
        $as->get(route('dashboard.payments.index', ['provider' => 'paymob', 'status' => 'paid', 'search' => (string) $order->id]))->assertOk()->assertSee($payment->internal_reference);
        $as->get(route('dashboard.payments.show', $payment))->assertOk()->assertSee('9001')->assertSee('webhook.success');
        $as->get(route('dashboard.payments.events', ['result' => 'rejected']))->assertOk()->assertSee('Invalid signature');
        $as->get(route('dashboard.payments.reports'))->assertOk()->assertSee('PAYMOB');
        $as->get(route('dashboard.refunds.index'))->assertOk()->assertSee($refund->reference);
        $as->get(route('dashboard.refunds.show', $refund))->assertOk()->assertSee('Refund through the provider');
        $as->get(route('dashboard.orders.show', $order))->assertOk()->assertSee($payment->internal_reference);
    }
}
