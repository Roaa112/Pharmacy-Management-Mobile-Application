<?php

namespace Tests\Feature\Concurrency;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Child;
use App\Models\LoyaltyLedgerEntry;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Vaccine;
use App\Modules\HealthService\Services\HealthReminderService;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShop;
use Tests\Concerns\RunsRaces;
use Tests\TestCase;

/** Races on the hardened paths: real processes against MySQL (see RunsRaces). */
class HardeningConcurrencyTest extends TestCase
{
    use DatabaseMigrations, BuildsShop, RunsRaces;

    private const PAYMOB = ['environment' => 'sandbox', 'flow' => 'intention', 'base_url' => 'https://accept.paymob.com', 'verify_with_api' => false,
        'amount_factor' => 1, 'currency' => 'EGP', 'sandbox' => ['hmac_secret' => 'concurrency-hmac', 'api_key' => 'k', 'secret_key' => 's',
            'public_key' => 'p', 'integration_ids' => '1']];

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency is verified on MySQL/MariaDB only (real row locks and REPEATABLE READ).');
        }
    }

    private function admins(int $count): array
    {
        return array_map(fn ($i) => Admin::create(['name' => "Admin {$i}", 'email' => "admin{$i}@example.com", 'password' => 'secret-password'])->id, range(1, $count));
    }

    public function test_admins_and_the_schedule_settling_one_unknown_refund_at_once_settle_it_once(): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), self::PAYMOB)]);
        $method = PaymentMethod::where('code', 'paymob')->firstOrFail();
        $order = Order::create(['user_id' => User::factory()->create()->id, 'payment_method' => 'paymob', 'payment_method_id' => $method->id, 'delivery_fee' => 0,
            'total_price' => 100, 'points_earned' => 0, 'status' => 'completed', 'currency_code' => 'EGP']);
        $payment = Payment::create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'provider' => 'paymob', 'internal_reference' => 'PAY-HC-1',
            'amount' => 100, 'currency' => 'EGP', 'status' => 'paid', 'provider_transaction_id' => '880001', 'provider_order_id' => '7701', 'paid_at' => now()]);
        $admins = $this->admins(6);
        $refund = app(RefundService::class)->requestForPayment($payment, 4000, 'Customer request', null);
        $refund->update(['status' => Refund::UNKNOWN, 'processed_by' => $admins[5]]);
        // Paymob shows the 40.00 refunded: every reconciliation agrees it was paid out.
        $transaction = ['id' => 880001, 'success' => true, 'pending' => false, 'amount_cents' => 10000, 'currency' => 'EGP', 'refunded_amount_cents' => 4000,
            'order' => ['id' => 7701, 'merchant_order_id' => 'PAY-HC-1']];

        $results = $this->race(array_merge(
            array_map(fn ($id) => ['reconcile_refund', ['refund_id' => $refund->id, 'admin_id' => $id, 'transaction' => $transaction, 'paymob' => self::PAYMOB]], array_slice($admins, 0, 4)),
            array_map(fn ($id) => ['resolve_refund', ['refund_id' => $refund->id, 'admin_id' => $id, 'paid_out' => true]], array_slice($admins, 4, 1)),
        ));

        $this->noExceptions($results);
        $this->assertSame(1, count(array_filter($results, fn ($r) => in_array($r['status'], ['succeeded'], true))), 'settled by exactly one of them');
        $this->assertSame([Refund::SUCCEEDED, 4000, 'partially_refunded'], [$refund->fresh()->status, Money::toMinor($payment->fresh()->refunded_amount), $payment->fresh()->status->value]);
        $this->assertSame(1, DB::table('payment_status_histories')->where('payment_id', $payment->id)->where('to_status', 'partially_refunded')->count());
    }

    public function test_simultaneous_on_account_supplier_payments_never_overpay_by_accident(): void
    {
        $supplier = Supplier::create(['code' => 'SUPH', 'name' => 'Supplier']);
        SupplierInvoice::create(['supplier_id' => $supplier->id, 'number' => 'INV-H', 'invoice_date' => now()->toDateString(), 'amount' => 100]);

        $results = $this->race(array_merge(
            array_map(fn ($i) => ['supplier_payment', ['supplier_id' => $supplier->id, 'amount' => 30, 'request_key' => "acct-{$i}"]], range(1, 8)),
            [['supplier_payment', ['supplier_id' => $supplier->id, 'amount' => 30, 'request_key' => 'acct-1']]], // the same form twice
        ));

        $this->noExceptions($results);
        $this->assertSame(3, SupplierPayment::count(), '3 x 30.00 fit in the 100.00 owed; the rest would be an accidental advance');
        $this->assertSame(1000, $supplier->balanceMinor());
        $this->assertSame(0, SupplierPayment::where('is_advance', true)->count());
    }

    public function test_several_admins_redeeming_the_same_points_redeem_them_once(): void
    {
        \App\Models\Setting::current()->update(['points_validity_days' => 30]);
        $order = $this->makeOrder(User::factory()->create(), $this->makeSize(), status: 'completed');
        $this->earnPoints($order, 25);

        $results = $this->race(array_map(fn ($id) => ['redeem_points', ['order_id' => $order->id, 'admin_id' => $id]], $this->admins(6)));

        $this->noExceptions($results);
        $this->assertSame(['redeemed:0' => 5, 'redeemed:1' => 1], $this->statuses($results));
        $this->assertSame([[-25]], [LoyaltyLedgerEntry::where('order_id', $order->id)->where('type', 'redeemed')->pluck('points')->all()]);
        $this->assertSame('redeemed', $order->fresh()->points_status);
    }

    public function test_one_customer_redeeming_from_several_devices_at_once_never_spends_more_than_they_have(): void
    {
        \App\Models\Setting::current()->update(['points_validity_days' => 30]);
        $customer = User::factory()->create();
        $this->earnPoints($this->makeOrder($customer, $this->makeSize(), status: 'completed'), 150);
        $reward = \App\Models\LoyaltyReward::create(['code' => 'RW-RACE', 'type' => 'voucher', 'title' => 'Voucher', 'points_required' => 100, 'stock' => 10, 'is_active' => true]);

        $results = $this->race(array_fill(0, 6, ['redeem_reward', ['user_id' => $customer->id, 'reward_id' => $reward->id]]));

        $this->noExceptions($results);
        $this->assertSame(['redeemed' => 1, 'refused' => 5], $this->statuses($results));
        $this->assertSame(50, app(\App\Modules\Points\Services\LoyaltyLedger::class)->balance($customer->id));
        $this->assertSame([9, 1], [$reward->fresh()->stock, $reward->fresh()->redeemed_count]);
        $this->assertGreaterThanOrEqual(0, (int) LoyaltyLedgerEntry::where('user_id', $customer->id)->min('remaining_points'));
    }

    public function test_many_customers_racing_for_the_last_reward_units_get_exactly_the_stock(): void
    {
        \App\Models\Setting::current()->update(['points_validity_days' => 30]);
        $reward = \App\Models\LoyaltyReward::create(['code' => 'RW-LAST', 'type' => 'voucher', 'title' => 'Voucher', 'points_required' => 10, 'stock' => 3, 'is_active' => true]);
        $customers = array_map(function () {
            $customer = User::factory()->create();
            $this->earnPoints($this->makeOrder($customer, $this->makeSize(), status: 'completed'), 10);

            return $customer->id;
        }, range(1, 7));

        $results = $this->race(array_map(fn ($id) => ['redeem_reward', ['user_id' => $id, 'reward_id' => $reward->id]], $customers));

        $this->noExceptions($results);
        $this->assertSame(['redeemed' => 3, 'refused' => 4], $this->statuses($results));
        $this->assertSame([0, 3], [$reward->fresh()->stock, $reward->fresh()->redeemed_count]);
        $this->assertSame(3, \App\Models\LoyaltyRedemption::where('reward_id', $reward->id)->count());
    }

    public function test_a_double_tapped_add_to_cart_leaves_one_line_with_every_unit(): void
    {
        $user = User::factory()->create();
        $size = $this->makeSize(price: 20, stock: 50);
        $token = $user->createToken('race')->plainTextToken;

        $results = $this->race(array_fill(0, 10, ['add_to_cart', ['product_id' => $size->product_id, 'size_id' => $size->id, 'quantity' => 2, 'token' => $token]]));

        $this->noExceptions($results);
        $this->assertSame(['200' => 10], $this->statuses($results));
        $this->assertSame([20], Cart::where('user_id', $user->id)->pluck('product_quantity')->all(), 'one line; no increment lost');
    }

    public function test_several_queue_workers_running_one_reminder_send_it_once(): void
    {
        $user = User::factory()->create();
        $child = Child::create(['user_id' => $user->id, 'child_name' => 'Sara', 'birth_date' => now()->subMonths(2)->toDateString(), 'gender' => 'أنثى']);
        $vaccine = Vaccine::create(['child_id' => $child->id, 'vaccine_name' => 'Polio', 'scheduled_date' => now()->addDay()->toDateString(), 'is_completed' => false]);
        $delivery = NotificationDelivery::create(['user_id' => $user->id, 'type' => HealthReminderService::VACCINE, 'reference_type' => 'vaccine',
            'reference_id' => $vaccine->id, 'occurrence' => $vaccine->scheduled_date->toDateString(), 'status' => NotificationDelivery::STATUS_SENDING]);

        $results = $this->race(array_fill(0, 8, ['reminder', ['delivery_id' => $delivery->id]]));

        $this->noExceptions($results);
        $this->assertSame(1, $user->notifications()->count(), 'sent exactly once');
        $this->assertSame([NotificationDelivery::STATUS_SENT, 1], [$delivery->fresh()->status, $delivery->fresh()->attempts]);
    }
}
