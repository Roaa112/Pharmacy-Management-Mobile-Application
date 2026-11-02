<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\FlashSale;
use App\Models\FlashSaleRedemption;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\PromotionRedemption;
use App\Models\ReturnRequest;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Orders\Services\ReturnDecisionService;
use App\Modules\Payments\Services\PaymentService;
use App\Support\StoreClock;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Business rules added by the remediation: returns follow dispatch and received money, flash-sale
 * and promotion uses follow the order's lifecycle (and a failed checkout uses nothing), stock races
 * answer 422, OTP guesses are capped in the database, an email change is re-verified, supplier
 * payments cannot overpay or double-submit.
 * Concurrency of the same rules is proven against MySQL in tests/Feature/Concurrency.
 */
class RemediationRulesTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    private function placeOrder(ProductSize $size, int $quantity = 1, array $data = []): Order
    {
        $this->putInCart($this->user, $size, $quantity);

        return Order::findOrFail($this->postJson('/api/user/orders', $data + ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    // ------------------------------------------------------------------ H2 returns

    public function test_an_undispatched_order_cannot_be_returned_or_refunded(): void
    {
        $order = $this->placeOrder($this->makeSize(price: 100, stock: 5));

        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 100])
            ->assertStatus(422)->assertJsonPath('code', 'order_not_delivered');

        // Even an admin cannot quarantine units that never left, nor refund money never received.
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 100]);
        try {
            app(ReturnDecisionService::class)->accept($return->id, 100, [$order->items()->value('id') => 1], $this->admin->id);
            $this->fail('An undispatched line must not be returnable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('never dispatched', $e->getMessage());
        }
        $this->assertSame('pending', $return->fresh()->status);
        $this->assertSame(0, (int) ProductSize::find($order->items()->value('product_size_id'))->quarantined_stock);
    }

    public function test_a_delivered_but_unpaid_order_cannot_be_refunded(): void
    {
        $order = $this->placeOrder($this->makeSize(price: 100, stock: 5));
        app(OrderWorkflow::class)->transition($order, OrderStatus::OnDelivery, 'system'); // shipped, cash not collected yet
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 100]);

        $this->expectExceptionMessage('No payment has been received');
        app(ReturnDecisionService::class)->accept($return->id, 100, [$order->items()->value('id') => 1], $this->admin->id);
    }

    public function test_a_refund_is_capped_by_the_money_received(): void
    {
        $order = $this->placeOrder($this->makeSize(price: 100, stock: 5));
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, 'system');
        $payment = $order->payments()->sole();
        $this->assertSame(10000, $payment->refundableMinor());

        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 60]);
        app(ReturnDecisionService::class)->accept($return->id, 60, [$order->items()->value('id') => 1], $this->admin->id);

        // 60 is pending refund: a second return can only ask for the remaining 40.
        $second = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'y', 'refund_amount' => 50]);
        $this->expectException(DomainException::class);
        app(ReturnDecisionService::class)->accept($second->id, 50, [], $this->admin->id);
    }

    // ------------------------------------------------------------------ C3 lifecycle of limited uses

    public function test_flash_sale_units_are_reserved_released_on_cancel_and_consumed_on_completion(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $this->flashSaleOn($size, 50, ['quantity_limit' => 2]);

        $first = $this->placeOrder($size, 2);
        $this->assertSame([FlashSaleRedemption::RESERVED, 2], [FlashSaleRedemption::sole()->status, FlashSaleRedemption::sole()->quantity]);

        // Sold out at the flash price for the next customer.
        $this->assertEquals(100, $this->placeOrder($size, 1)->total_price);

        // Canceling gives the units back to the sale.
        app(OrderWorkflow::class)->transition($first, OrderStatus::Canceled, 'admin', $this->admin->id);
        $this->assertSame(FlashSaleRedemption::RELEASED, FlashSaleRedemption::where('order_id', $first->id)->value('status'));
        $again = $this->placeOrder($size, 1);
        $this->assertEquals(50, $again->total_price);

        app(OrderWorkflow::class)->transition($again, OrderStatus::Completed, 'system');
        $this->assertSame(FlashSaleRedemption::CONSUMED, FlashSaleRedemption::where('order_id', $again->id)->value('status'));
    }

    public function test_a_failed_checkout_uses_no_promotion_or_flash_sale_budget(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $this->promotion('percent', ['discount_value' => 10, 'usage_limit' => 1], [['product', $size->product_id]]);
        FlashSale::query()->delete();

        // The payment record fails to save after the promotion use was reserved: everything rolls back.
        $this->mock(PaymentService::class, fn ($mock) => $mock->shouldReceive('providerReady')->andReturnTrue()
            ->shouldReceive('createForCheckout')->andThrow(new \RuntimeException('database went away')));
        $this->putInCart($this->user, $size, 1);
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash']);
            $this->fail('The checkout should have failed.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, PromotionRedemption::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(10, $size->fresh()->stock, 'no stock reserved');
    }

    // ------------------------------------------------------------------ M2 stock races

    public function test_a_reservation_short_of_stock_answers_422_not_500(): void
    {
        $size = $this->makeSize(price: 100, stock: 1);
        // The catalogue projection says 5 but the ledger holds 1 (a projection behind the balances).
        ProductSize::whereKey($size->id)->update(['stock' => 5]);
        $this->putInCart($this->user, $size, 3);

        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
        $this->assertSame(0, Order::count());
    }

    // ------------------------------------------------------------------ H3 OTP

    public function test_only_five_otp_guesses_are_ever_compared_then_the_code_is_destroyed(): void
    {
        Mail::fake();
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $victim->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0])->save();

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])->postJson('/api/user/password/verify-otp', ['email' => 'victim@example.com', 'otp_code' => '000000'])->assertStatus(422);
        }
        $this->assertNull($victim->fresh()->otp_code, 'destroyed after the fifth wrong guess');

        // The right code no longer works either (from yet another address).
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->postJson('/api/user/password/verify-otp', ['email' => 'victim@example.com', 'otp_code' => '123456'])->assertStatus(422);
    }

    public function test_the_attempt_is_counted_before_the_code_is_compared(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        // Five guesses already counted (e.g. by parallel requests): the right code is not even compared.
        $victim->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 5])->save();

        $this->postJson('/api/user/password/verify-otp', ['email' => 'victim@example.com', 'otp_code' => '123456'])->assertStatus(422);
        $this->assertNull($victim->fresh()->otp_code);
    }

    /** The app's reset flow: verify the code, then reset the password with the same code. */
    private function resetFlow(User $user, int $typos, string $code = '135790'): array
    {
        foreach (range(1, $typos) as $i) {
            if ($typos > 0) {
                $this->withServerVariables(['REMOTE_ADDR' => "10.3.{$typos}.{$i}"])->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => '000000'])->assertStatus(422);
            }
        }
        $verify = $this->withServerVariables(['REMOTE_ADDR' => "10.4.{$typos}.1"])->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => $code])->status();
        $reset = $this->withServerVariables(['REMOTE_ADDR' => "10.4.{$typos}.2"])->postJson('/api/password/reset/auth',
            ['email' => $user->email, 'otp_code' => $code, 'password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])->status();

        return [$verify, $reset];
    }

    private function userWithCode(array $attributes = []): User
    {
        $user = User::factory()->create();
        $user->forceFill($attributes + ['otp_code' => Hash::make('135790'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0])->save();

        return $user;
    }

    public function test_a_correct_code_right_away_verifies_and_resets(): void
    {
        $user = $this->userWithCode();

        $this->assertSame([200, 200], $this->resetFlow($user, 0));
        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
        $this->assertNull($user->fresh()->otp_code, 'used up by the reset');
    }

    public function test_a_correct_code_after_four_typos_still_completes_the_reset(): void
    {
        $user = $this->userWithCode();

        $this->assertSame([200, 200], $this->resetFlow($user, 4));
        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
    }

    public function test_a_correct_code_resets_the_count_but_wrong_guesses_stay_capped(): void
    {
        $user = $this->userWithCode();
        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.5.0.{$i}"])->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => '000000']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.5.1.1'])->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => '135790'])->assertOk();
        $this->assertSame(0, $user->fresh()->otp_attempts);

        // Still only five wrong guesses before the code is destroyed.
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.5.2.{$i}"])->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => '000000'])->assertStatus(422);
        }
        $this->assertNull($user->fresh()->otp_code);
    }

    public function test_an_expired_code_is_refused_and_counts_nothing(): void
    {
        $user = $this->userWithCode(['otp_expires_at' => now()->subMinute()]);

        $this->assertSame([422, 422], $this->resetFlow($user, 0));
        $this->assertSame(0, $user->fresh()->otp_attempts);
        $this->assertFalse(Hash::check('NewPassword1', (string) $user->fresh()->password));
    }

    public function test_new_codes_are_capped_per_account_whatever_the_ip(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'victim@example.com']);

        foreach (range(1, 7) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->postJson('/api/user/password/forgot', ['email' => 'victim@example.com'])->assertOk();
        }

        $this->assertSame(5, count(Mail::queued(\App\Mail\SendOtp::class)) + count(Mail::sent(\App\Mail\SendOtp::class)));
    }

    // ------------------------------------------------------------------ M6 email change

    public function test_an_email_change_is_reverified_and_signs_out_other_sessions(): void
    {
        Mail::fake();
        $this->user->forceFill(['is_verified' => true])->save();
        $this->user->createToken('other-device');

        $this->putJson("/api/user/update/{$this->user->id}", ['name' => 'N', 'email' => 'new@example.com', 'phone' => '01011111111'])->assertOk();

        $this->assertFalse($this->user->fresh()->is_verified);
        $this->assertNotNull($this->user->fresh()->otp_code, 'a code was sent to the new address');
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertTrue(Mail::queued(\App\Mail\SendOtp::class, fn ($mail) => $mail->hasTo('new@example.com'))->isNotEmpty()
            || Mail::sent(\App\Mail\SendOtp::class, fn ($mail) => $mail->hasTo('new@example.com'))->isNotEmpty());
    }

    public function test_an_email_change_requires_the_current_password_when_configured(): void
    {
        config(['auth.password_change_requires_current' => true]);
        $this->user->forceFill(['password' => Hash::make('old-password')])->save();

        $this->putJson("/api/user/update/{$this->user->id}", ['name' => 'N', 'email' => 'new@example.com', 'phone' => '01011111111'])->assertStatus(422);
        $this->putJson("/api/user/update/{$this->user->id}", ['name' => 'N', 'email' => $this->user->email, 'phone' => '01011111111'])->assertOk();
    }

    // ------------------------------------------------------------------ M4 supplier payments

    public function test_supplier_payments_cannot_overpay_an_invoice_and_a_resubmitted_form_records_once(): void
    {
        $supplier = Supplier::create(['code' => 'SUP1', 'name' => 'Supplier']);
        $invoice = SupplierInvoice::create(['supplier_id' => $supplier->id, 'number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'amount' => 100]);
        $form = ['supplier_invoice_id' => $invoice->id, 'amount' => 70, 'paid_on' => now()->toDateString(), 'request_key' => 'form-1'];

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.suppliers.payments.store', $supplier), $form)->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.suppliers.payments.store', $supplier), $form)->assertSessionHas('success');
        $this->assertSame(1, SupplierPayment::count(), 'the same form twice is one payment');

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.suppliers.payments.store', $supplier), ['request_key' => 'form-2', 'amount' => 40] + $form)->assertSessionHas('error');
        $this->assertEquals(70, SupplierPayment::sum('amount'));
    }
}
