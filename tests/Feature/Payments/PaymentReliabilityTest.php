<?php

namespace Tests\Feature\Payments;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Failure recovery around Paymob: events whose processing died, browser redirects racing the
 * webhook, expiry only after asking Paymob, duplicate captures across attempts, refunds whose
 * outcome is unknown. All provider HTTP is faked; stray requests fail the test.
 */
class PaymentReliabilityTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;
    private Admin $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), [
            'environment' => 'sandbox', 'flow' => 'intention', 'base_url' => 'https://accept.paymob.com',
            'checkout_url' => 'https://eg.checkout.paymob.com/', 'verify_with_api' => false, 'currency' => null,
            'amount_factor' => 1, 'payment_ttl_minutes' => 60, 'return_url' => 'elkawther://payment-result',
            'sandbox' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret', 'public_key' => 'test-public',
                'hmac_secret' => 'test-hmac', 'integration_ids' => '111', 'iframe_id' => '333'],
        ])]);
        PaymentMethod::where('code', 'paymob')->update(['is_active' => true]);
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->cashier = Admin::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    private function fake(array $extra = []): void
    {
        Http::fake($extra + [
            '*/v1/intention/*' => Http::sequence()->push(['id' => 'pi_1', 'client_secret' => 'csk_1', 'intention_order_id' => 555], 201)
                ->push(['id' => 'pi_2', 'client_secret' => 'csk_2', 'intention_order_id' => 556], 201),
            '*/api/auth/tokens' => Http::response(['token' => 'auth-token']),
        ]);
    }

    private function order(): Order
    {
        $this->putInCart($this->user, $this->makeSize(price: 100, stock: 5), 1);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'paymob'])->assertCreated()->json('order_id'));
    }

    private function tx(Payment $payment, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 9001, 'amount_cents' => $payment->amountMinor(), 'created_at' => '2026-11-01T10:00:00.000000',
            'currency' => 'EGP', 'error_occured' => false, 'has_parent_transaction' => false, 'integration_id' => 111,
            'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $payment->provider_order_id, 'merchant_order_id' => $payment->internal_reference],
            'owner' => 1, 'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ], $overrides);
    }

    private function webhook(array $tx)
    {
        return $this->postJson('/api/webhooks/payments/paymob?hmac='.app(PaymobGateway::class)->signature($tx), ['type' => 'TRANSACTION', 'obj' => $tx]);
    }

    private function redirect(array $tx)
    {
        $flat = collect(\Illuminate\Support\Arr::dot($tx))->map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v)->all();
        $flat['order'] = $tx['order']['id'];
        $flat['merchant_order_id'] = $tx['order']['merchant_order_id'];
        unset($flat['order.id'], $flat['order.merchant_order_id']);
        $flat['hmac'] = app(PaymobGateway::class)->signature($tx);

        return $this->get('/payments/paymob/return?'.http_build_query($flat));
    }

    /** What a worker killed mid-processing leaves behind. */
    private function deadEvent(Payment $payment, array $tx, int $minutesAgo = 10): PaymentEvent
    {
        $notification = app(PaymobGateway::class)->parseNotification(['hmac' => app(PaymobGateway::class)->signature($tx)], ['obj' => $tx], false);

        return PaymentEvent::create([
            'provider' => 'paymob', 'event_type' => 'webhook.success', 'provider_event_id' => (string) $tx['id'],
            'idempotency_key' => "paymob:txn:{$tx['id']}:success", 'signature_valid' => true,
            'result' => PaymentEvent::RESULT_PROCESSING, 'processing_started_at' => now()->subMinutes($minutesAgo), 'attempts' => 1,
            'payload' => $notification->raw,
        ]);
    }

    // ------------------------------------------------------------------ C2: stuck events

    public function test_a_provider_retry_reclaims_an_event_left_processing_by_a_dead_worker(): void
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();
        $this->deadEvent($payment, $this->tx($payment));

        $this->webhook($this->tx($payment))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame([PaymentEvent::RESULT_PROCESSED, 2], [PaymentEvent::where('provider_event_id', '9001')->sole()->result, PaymentEvent::where('provider_event_id', '9001')->sole()->attempts]);
    }

    public function test_an_event_in_progress_elsewhere_asks_the_provider_to_retry(): void
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();
        $this->deadEvent($payment, $this->tx($payment), minutesAgo: 0);

        $this->webhook($this->tx($payment))->assertStatus(503)->assertJsonPath('status', 'in_progress');
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_the_recovery_command_applies_events_whose_processing_died(): void
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();
        $this->deadEvent($payment, $this->tx($payment));
        // A legacy "received" row (stored before claims existed) is retryable too.
        PaymentEvent::where('provider_event_id', '9001')->sole()->update(['result' => PaymentEvent::RESULT_RECEIVED, 'processing_started_at' => null]);

        $this->artisan('payments:recover-events')->assertSuccessful();

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame(PaymentEvent::RESULT_PROCESSED, PaymentEvent::where('provider_event_id', '9001')->sole()->result);
    }

    public function test_events_out_of_retries_are_reported_not_retried(): void
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();
        $this->deadEvent($payment, $this->tx($payment))->forceFill(['result' => PaymentEvent::RESULT_FAILED, 'attempts' => PaymentEvent::MAX_ATTEMPTS, 'created_at' => now()->subHour()])->save();

        $this->assertSame(1, app(PaymentService::class)->recoverEvents()['exhausted']);
        $this->assertSame(1, app(PaymentService::class)->staleEvents()['exhausted']);
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_browser_redirect_cannot_make_the_webhook_a_duplicate(): void
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();

        // The redirect arrives first and its processing fails on our side.
        PaymentEvent::create(['provider' => 'paymob', 'event_type' => 'redirect.success', 'provider_event_id' => '9001',
            'idempotency_key' => 'paymob:redirect:txn:9001:success', 'signature_valid' => true, 'result' => PaymentEvent::RESULT_FAILED, 'attempts' => 1]);

        $this->webhook($this->tx($payment))->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame('paid', $payment->fresh()->status->value);

        // And a redirect after the webhook is applied once, harmlessly.
        $this->redirect($this->tx($payment))->assertRedirect();
        $this->assertSame(1, $payment->history()->where('to_status', 'paid')->count());
    }

    public function test_expiry_finds_a_payment_whose_webhook_never_arrived_through_the_provider_order(): void
    {
        $this->fake();
        $order = $this->order();
        $payment = $order->payments()->sole();
        $tx = $this->tx($payment);
        Http::fake(['*/api/ecommerce/orders/transaction_inquiry' => Http::response($tx)]);

        $this->travel(61)->minutes();
        $this->artisan('payments:expire-stale')->assertSuccessful();

        $this->assertSame(['paid', '9001'], [$payment->fresh()->status->value, $payment->fresh()->provider_transaction_id]);
        $this->assertSame('pending', $order->fresh()->status, 'the paid order is not canceled');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction_inquiry') && $r['order_id'] === '555');
    }

    public function test_expiry_waits_when_the_provider_cannot_be_asked(): void
    {
        $this->fake(['*/api/ecommerce/orders/transaction_inquiry' => fn () => throw new ConnectionException('timeout')]);
        $order = $this->order();

        $this->travel(61)->minutes();
        $this->artisan('payments:expire-stale')->assertSuccessful();

        $this->assertSame('pending', $order->payments()->sole()->status->value);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_without_the_api_key_expiry_waits_a_grace_period_and_unresolved_events(): void
    {
        $this->fake();
        $order = $this->order();
        config(['services.paymob.sandbox.api_key' => null]);
        $payment = $order->payments()->sole();

        $this->travel(61)->minutes();
        app(PaymentService::class)->expireStale();
        $this->assertSame('pending', $payment->fresh()->status->value, 'inside the grace period');

        $this->travel(31)->minutes();
        app(PaymentService::class)->expireStale();
        $this->assertSame('expired', $payment->fresh()->status->value);
        $this->assertSame('canceled', $order->fresh()->status);
    }

    // ------------------------------------------------------------------ C4: duplicate captures

    public function test_two_attempts_both_captured_keep_one_payment_and_refund_the_other(): void
    {
        $this->fake();
        $order = $this->order();
        $first = $order->payments()->sole();
        $this->postJson("/api/user/orders/{$order->id}/pay")->assertOk();
        $second = Payment::where('order_id', $order->id)->orderByDesc('id')->first();
        $this->assertSame('canceled', $first->fresh()->status->value, 'superseded locally only');
        $this->assertTrue($first->fresh()->metadata['provider_may_still_capture']);

        // Both checkout pages were completed at Paymob.
        $this->webhook($this->tx($second, ['id' => 9002]))->assertOk();
        $this->webhook($this->tx($first, ['id' => 9001]))->assertOk();

        $first->refresh();
        $this->assertSame(['paid', $second->id], [$first->status->value, $first->duplicate_of_payment_id]);
        $this->assertStringContainsString('Duplicate capture', $first->metadata['needs_review']);
        $refund = Refund::sole();
        $this->assertSame([$first->id, 100.0, 'pending', null], [$refund->payment_id, (float) $refund->amount, $refund->status, $refund->requested_by]);
        $this->assertSame(['paid', 'none'], [$order->fresh()->payment_status, $order->fresh()->refund_status]);

        // System-requested: any refunds admin pays it out; the order's own refund status is unaffected.
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9100, 'success' => true, 'pending' => false])]);
        app(RefundService::class)->process($refund, $this->admin->id);
        $this->assertSame('refunded', $first->fresh()->status->value);
        $this->assertSame(['paid', 'none'], [$order->fresh()->payment_status, $order->fresh()->refund_status]);
        $this->postJson("/api/user/orders/{$order->id}/pay")->assertStatus(422);
    }

    public function test_paying_one_attempt_closes_the_other_open_attempts(): void
    {
        $this->fake();
        $order = $this->order();
        $first = $order->payments()->sole();
        $this->postJson("/api/user/orders/{$order->id}/pay")->assertOk();
        $second = Payment::where('order_id', $order->id)->orderByDesc('id')->first();

        // The superseded attempt is the one actually paid.
        $this->webhook($this->tx($first))->assertOk();

        $this->assertSame(['paid', null], [$first->fresh()->status->value, $first->fresh()->duplicate_of_payment_id]);
        $this->assertSame('canceled', $second->fresh()->status->value);
        $this->assertSame('paid', $order->fresh()->payment_status, 'projected from the paying attempt, not the latest');
        $this->assertSame(0, Refund::count());
    }

    // ------------------------------------------------------------------ H1 / M5: refunds

    private function paidOrderWithRefund(int $amount = 100): Refund
    {
        $this->fake();
        $payment = $this->order()->payments()->sole();
        $this->webhook($this->tx($payment))->assertOk();

        return app(RefundService::class)->requestForPayment($payment->fresh(), $amount * 100, 'Customer request', $this->admin->id);
    }

    public function test_a_refund_without_an_answer_is_unknown_and_cannot_be_resent(): void
    {
        $refund = $this->paidOrderWithRefund();
        Http::fake(['*/api/acceptance/void_refund/refund' => fn () => throw new ConnectionException('timeout')]);

        $refund = app(RefundService::class)->process($refund, $this->cashier->id);
        $this->assertSame(Refund::UNKNOWN, $refund->status);
        $this->assertSame('paid', $refund->payment->status->value, 'nothing recorded as refunded');

        $this->expectException(DomainException::class);
        app(RefundService::class)->process($refund, $this->cashier->id);
    }

    public function test_a_provider_server_error_is_unknown_too(): void
    {
        $refund = $this->paidOrderWithRefund();
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response('bad gateway', 502)]);

        $this->assertSame(Refund::UNKNOWN, app(RefundService::class)->process($refund, $this->cashier->id)->status);
    }

    public function test_reconciliation_settles_an_unknown_refund_from_the_provider_record(): void
    {
        $refund = $this->paidOrderWithRefund(40);
        $refund->update(['status' => Refund::UNKNOWN]);
        $payment = $refund->payment;

        // Paymob says 40.00 was refunded on the transaction: it was paid out.
        Http::fake(['*/api/acceptance/transactions/9001' => Http::response($this->tx($payment, ['refunded_amount_cents' => 4000]))]);
        $this->artisan('payments:reconcile-refunds')->assertSuccessful();

        $this->assertSame(Refund::SUCCEEDED, $refund->fresh()->status);
        $this->assertSame('partially_refunded', $payment->fresh()->status->value);
    }

    public function test_a_refund_the_provider_does_not_show_yet_stays_unknown_until_a_person_confirms(): void
    {
        $refund = $this->paidOrderWithRefund(40);
        $refund->update(['status' => Refund::UNKNOWN, 'processed_by' => $this->cashier->id]);
        Http::fake(['*/api/acceptance/transactions/9001' => Http::response($this->tx($refund->payment, ['refunded_amount_cents' => 0]))]);

        // Absence proves nothing (the provider may still be processing it): never resendable by itself.
        $this->assertSame('not_seen', app(RefundService::class)->reconcile($refund)['outcome']);
        $this->assertSame(Refund::UNKNOWN, $refund->fresh()->status);
        try {
            app(RefundService::class)->process($refund->fresh(), $this->admin->id);
            $this->fail('An unknown refund was sent again.');
        } catch (DomainException) {
        }

        // The admin who sent it cannot vouch for it; another one confirms from the provider dashboard.
        try {
            app(RefundService::class)->resolveUnknown($refund->fresh(), false, $this->cashier->id, 'Not in the Paymob dashboard');
            $this->fail('The sender settled their own unknown refund.');
        } catch (DomainException) {
        }
        $this->assertSame(Refund::FAILED, app(RefundService::class)->resolveUnknown($refund->fresh(), false, $this->admin->id, 'Not in the Paymob dashboard')->status);

        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9100, 'success' => true, 'pending' => false])]);
        $this->assertSame(Refund::SUCCEEDED, app(RefundService::class)->process($refund->fresh(), $this->cashier->id)->status);
    }

    public function test_reconciliation_leaves_an_ambiguous_refund_for_a_person(): void
    {
        $refund = $this->paidOrderWithRefund(40);
        $refund->update(['status' => Refund::UNKNOWN]);
        Http::fake(['*/api/acceptance/transactions/9001' => Http::response($this->tx($refund->payment, ['refunded_amount_cents' => 1500]))]);

        $this->assertSame('ambiguous', app(RefundService::class)->reconcile($refund)['outcome']);
        $this->assertSame(Refund::UNKNOWN, $refund->fresh()->status);
    }

    public function test_refunds_in_flight_count_against_what_can_still_be_refunded(): void
    {
        $refund = $this->paidOrderWithRefund(60);
        $refund->update(['status' => Refund::UNKNOWN]); // 60 possibly paid out already

        // Another refund of 60 on the 100 payment: refused at request time and at processing time.
        try {
            app(RefundService::class)->requestForPayment($refund->payment, 6000, 'again', $this->admin->id);
            $this->fail('Open refunds must cap new requests.');
        } catch (DomainException) {
        }

        $legacy = Refund::create(['reference' => 'RF-LEGACY', 'order_id' => $refund->order_id, 'payment_id' => $refund->payment_id,
            'amount' => 60, 'method' => Refund::METHOD_GATEWAY, 'status' => Refund::PENDING]);
        $this->expectExceptionMessage('can still be refunded');
        app(RefundService::class)->process($legacy, $this->cashier->id);
    }

    public function test_the_requesting_admin_cannot_pay_out_their_own_refund(): void
    {
        $refund = $this->paidOrderWithRefund();

        try {
            app(RefundService::class)->process($refund, $this->admin->id);
            $this->fail('Dual control must refuse the requester.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Separation of duties', $e->getMessage());
        }

        config(['pharmacy.refund_dual_control' => false]);
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9100, 'success' => true, 'pending' => false])]);
        $this->assertSame(Refund::SUCCEEDED, app(RefundService::class)->process($refund, $this->admin->id)->status);
    }
}
