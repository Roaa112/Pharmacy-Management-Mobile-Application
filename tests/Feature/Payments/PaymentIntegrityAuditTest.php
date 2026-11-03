<?php

namespace Tests\Feature\Payments;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Modules\Payments\Services\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Final audit: payment and refund integrity under hostile or broken provider traffic. Provider
 * HTTP is faked (no Paymob sandbox credentials exist in this environment); stray requests fail.
 */
class PaymentIntegrityAuditTest extends TestCase
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
        Http::fake([
            '*/v1/intention/*' => Http::sequence()->push(['id' => 'pi_1', 'client_secret' => 'csk_1', 'intention_order_id' => 555], 201)
                ->push(['id' => 'pi_2', 'client_secret' => 'csk_2', 'intention_order_id' => 556], 201),
            '*/api/auth/tokens' => Http::response(['token' => 'auth-token']),
        ]);
    }

    private function order(float $price = 100): Order
    {
        $this->putInCart($this->user, $this->makeSize(price: $price, stock: 5), 1);

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

    /** A provider-side refund transaction of $amountMinor on the payment's transaction. */
    private function refundTx(Payment $payment, int $id, int $amountMinor): array
    {
        return $this->tx($payment, ['id' => $id, 'amount_cents' => $amountMinor, 'has_parent_transaction' => true, 'parent_transaction' => 9001,
            'is_refunded' => true, 'is_standalone_payment' => false]);
    }

    private function paidPayment(): Payment
    {
        $payment = $this->order()->payments()->sole();
        $this->webhook($this->tx($payment))->assertOk()->assertJsonPath('status', 'processed');

        return $payment->fresh();
    }

    // ------------------------------------------------------------------ webhook forgery

    public function test_a_signed_transaction_cannot_be_redirected_to_another_payment_by_its_unsigned_reference(): void
    {
        // Two orders of the same amount. The customer pays the first and gets its signed callback;
        // order.merchant_order_id is not covered by Paymob's HMAC, so they point it at the second.
        $first = $this->order()->payments()->sole();
        $second = $this->order()->payments()->sole();
        $this->assertSame(['555', '556'], [$first->provider_order_id, $second->provider_order_id]);

        $forged = $this->tx($first, ['order' => ['merchant_order_id' => $second->internal_reference]]);
        $this->webhook($forged)->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame('pending', $second->fresh()->status->value, 'the second order is not paid by the first order\'s transaction');
        $this->assertNull($second->fresh()->provider_transaction_id);

        // The genuine callback still pays the order it belongs to.
        $this->webhook($this->tx($first))->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame('paid', $first->fresh()->status->value);
    }

    // ------------------------------------------------------------------ provider refunds

    public function test_a_provider_refund_is_recorded_for_its_own_amount_not_matched_to_a_different_pending_refund(): void
    {
        $payment = $this->paidPayment();
        // An admin asked for 50.00 back; nobody has paid it out yet.
        $pending = app(RefundService::class)->requestForPayment($payment, 5000, 'Customer request', $this->admin->id);

        // Meanwhile 30.00 is refunded from Paymob's own dashboard.
        $this->webhook($this->refundTx($payment, 9200, 3000))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(Refund::PENDING, $pending->fresh()->status, 'the 50.00 request was not paid out');
        $this->assertSame(30.0, (float) $payment->fresh()->refunded_amount, 'the books show what Paymob refunded');
        $this->assertSame([3000], Refund::where('status', Refund::SUCCEEDED)->pluck('amount')->map(fn ($a) => \App\Support\Money::toMinor($a))->all());
        $this->assertSame('partially_refunded', $payment->fresh()->status->value);

        // What is left (70.00) still caps the pending request: 50.00 can still be paid out, not more.
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9300, 'success' => true, 'pending' => false])]);
        $this->assertSame(Refund::SUCCEEDED, app(RefundService::class)->process($pending, $this->cashier->id)->status);
        $this->assertSame(80.0, (float) $payment->fresh()->refunded_amount);
    }

    public function test_a_void_at_the_provider_records_the_whole_payment_not_a_smaller_open_request(): void
    {
        $payment = $this->paidPayment();
        $partial = app(RefundService::class)->requestForPayment($payment, 3000, 'Customer request', $this->admin->id);

        $this->webhook($this->tx($payment, ['id' => 9001, 'is_voided' => true]))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame([100.0, 'refunded'], [(float) $payment->fresh()->refunded_amount, $payment->fresh()->status->value]);
        $this->assertSame(Refund::PENDING, $partial->fresh()->status);
        $this->assertSame('full', $payment->order->fresh()->refund_status);
    }

    public function test_a_refund_callback_arriving_before_the_api_answer_settles_the_refund_in_flight(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        $refund->update(['status' => Refund::PROCESSING]);

        $this->webhook($this->refundTx($payment, 9200, 4000))->assertOk();
        $this->webhook($this->refundTx($payment, 9200, 4000))->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame([Refund::SUCCEEDED, '9200'], [$refund->fresh()->status, $refund->fresh()->provider_refund_id]);
        $this->assertSame(1, Refund::count());
        $this->assertSame(40.0, (float) $payment->fresh()->refunded_amount);
    }

    public function test_a_provider_refund_is_recorded_even_when_open_requests_already_cover_the_payment(): void
    {
        $payment = $this->paidPayment();
        $open = app(RefundService::class)->requestForPayment($payment, 10000, 'Customer request', $this->admin->id);

        // Paymob refunded 25.00 at the provider. The money moved: the books must say so.
        $this->webhook($this->refundTx($payment, 9200, 2500))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(25.0, (float) $payment->fresh()->refunded_amount);
        // The open 100.00 request can no longer be paid out in full: only 75.00 is left.
        $this->expectException(DomainException::class);
        app(RefundService::class)->process($open, $this->cashier->id);
    }

    // ------------------------------------------------------------------ stuck refunds

    public function test_a_refund_left_processing_by_a_dead_worker_is_reconciled(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        // The worker marked it processing, then died before Paymob's answer was recorded.
        $refund->forceFill(['status' => Refund::PROCESSING, 'processed_by' => $this->cashier->id])->save();
        Refund::whereKey($refund->id)->update(['updated_at' => now()->subHour()]);

        Http::fake(['*/api/acceptance/transactions/9001' => Http::response($this->tx($payment, ['refunded_amount_cents' => 4000]))]);
        $this->artisan('payments:reconcile-refunds')->assertSuccessful();

        $this->assertSame(Refund::SUCCEEDED, $refund->fresh()->status);
        $this->assertSame(40.0, (float) $payment->fresh()->refunded_amount);
    }

    /** A refund Paymob accepted as pending (we hold its refund transaction id). */
    private function refundPendingAtProvider(): Refund
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        // Paymob answers pending; a later resend (when allowed) succeeds.
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::sequence()->push(['id' => 9200, 'success' => false, 'pending' => true])
            ->push(['id' => 9300, 'success' => true, 'pending' => false])]);
        $refund = app(RefundService::class)->process($refund, $this->cashier->id);
        $this->assertSame([Refund::PROCESSING, '9200'], [$refund->status, $refund->provider_refund_id]);

        return $refund;
    }

    private function providerRefundTx(Payment $payment, bool $success, bool $pending): array
    {
        return array_replace($this->refundTx($payment, 9200, 4000), ['success' => $success, 'pending' => $pending]);
    }

    public function test_a_refund_the_provider_is_still_processing_is_never_resent(): void
    {
        $refund = $this->refundPendingAtProvider();
        Http::fake(['*/api/acceptance/transactions/9200' => Http::response($this->providerRefundTx($refund->payment, false, true))]);

        $this->assertSame('still_processing', app(RefundService::class)->reconcile($refund)['outcome']);
        $this->assertSame(Refund::PROCESSING, $refund->fresh()->status);
        $this->expectException(DomainException::class);
        app(RefundService::class)->process($refund->fresh(), $this->admin->id);
    }

    public function test_a_refund_the_provider_paid_while_our_worker_crashed_is_recorded_once(): void
    {
        $refund = $this->refundPendingAtProvider();
        $refund->update(['status' => Refund::UNKNOWN]); // the worker died before recording the answer
        Http::fake(['*/api/acceptance/transactions/9200' => Http::response($this->providerRefundTx($refund->payment, true, false))]);

        $this->assertSame('succeeded', app(RefundService::class)->reconcile($refund)['outcome']);
        // Reconciling again (the schedule, a second admin) changes nothing.
        $this->assertSame('not_unknown', app(RefundService::class)->reconcile($refund)['outcome']);

        $this->assertSame([Refund::SUCCEEDED, 40.0], [$refund->fresh()->status, (float) $refund->payment->fresh()->refunded_amount]);
    }

    public function test_a_refund_the_provider_declined_becomes_resendable(): void
    {
        $refund = $this->refundPendingAtProvider();
        Http::fake(['*/api/acceptance/transactions/9200' => Http::response($this->providerRefundTx($refund->payment, false, false))]);

        $this->assertSame('failed', app(RefundService::class)->reconcile($refund)['outcome']);
        $this->assertSame([Refund::FAILED, 0.0], [$refund->fresh()->status, (float) $refund->payment->fresh()->refunded_amount]);

        $this->assertSame(Refund::SUCCEEDED, app(RefundService::class)->process($refund->fresh(), $this->cashier->id)->status);
    }

    public function test_an_unusable_provider_answer_leaves_the_refund_unknown(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        $refund->update(['status' => Refund::UNKNOWN]);

        Http::fake(['*/api/acceptance/transactions/9001' => Http::sequence()->push('upstream error', 502)->push(['id' => 9001])]); // then: no refunded total
        $this->assertSame('provider_unreachable', app(RefundService::class)->reconcile($refund)['outcome']);
        $this->assertSame('cannot_check', app(RefundService::class)->reconcile($refund)['outcome']);

        $this->assertSame([Refund::UNKNOWN, 0.0], [$refund->fresh()->status, (float) $payment->fresh()->refunded_amount]);
    }

    public function test_an_unknown_refund_is_settled_by_hand_only_once(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        $refund->update(['status' => Refund::UNKNOWN, 'processed_by' => $this->cashier->id]);

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.refunds.resolve', $refund), ['outcome' => 'paid_out', 'note' => 'Shown as refunded in Paymob'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.refunds.resolve', $refund), ['outcome' => 'not_paid_out', 'note' => 'Again'])
            ->assertSessionHas('error');

        $this->assertSame([Refund::SUCCEEDED, 40.0], [$refund->fresh()->status, (float) $payment->fresh()->refunded_amount]);
        $this->assertSame(1, \App\Models\AdminAuditLog::where('action', 'refund.resolve')->count());
    }

    public function test_a_refund_processing_right_now_is_not_touched_by_reconciliation(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', $this->admin->id);
        $refund->forceFill(['status' => Refund::PROCESSING])->save();

        $this->artisan('payments:reconcile-refunds')->assertSuccessful();

        $this->assertSame(Refund::PROCESSING, $refund->fresh()->status);
    }

    public function test_a_refund_answered_with_another_amount_is_reconciled_not_recorded_as_requested(): void
    {
        $payment = $this->paidPayment();
        $refund = app(RefundService::class)->requestForPayment($payment, 5000, 'Customer request', $this->admin->id);
        Http::fake(['*/api/acceptance/void_refund/refund' => Http::response(['id' => 9300, 'success' => true, 'pending' => false, 'amount_cents' => 3000])]);

        $refund = app(RefundService::class)->process($refund, $this->cashier->id);

        $this->assertSame(Refund::UNKNOWN, $refund->status);
        $this->assertSame([0.0, 'paid'], [(float) $payment->fresh()->refunded_amount, $payment->fresh()->status->value]);
        // The provider's record decides; a partial difference is left for a person.
        Http::fake(['*/api/acceptance/transactions/9001' => Http::response($this->tx($payment, ['refunded_amount_cents' => 3000]))]);
        $this->assertSame('ambiguous', app(RefundService::class)->reconcile($refund)['outcome']);
    }

    public function test_a_canceled_refund_cannot_be_recorded_as_paid_out(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 60));
        $payment = $this->payInCash($order);
        $refund = app(RefundService::class)->requestForPayment($payment, 6000, 'Customer request', $this->admin->id);
        app(RefundService::class)->cancel($refund, $this->admin->id, 'Customer kept the goods');

        try {
            app(RefundService::class)->markSucceeded($refund, null, $this->cashier->id, 'CASH-1');
            $this->fail('A canceled refund was marked paid out.');
        } catch (DomainException) {
        }

        $this->assertSame([Refund::CANCELED, 0.0, 'paid'], [$refund->fresh()->status, (float) $payment->fresh()->refunded_amount, $payment->fresh()->status->value]);
    }
}
