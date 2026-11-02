<?php

namespace Tests\Feature\Concurrency;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\FlashSaleRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PromotionRedemption;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Real concurrency against MySQL/MariaDB: every scenario starts separate PHP processes
 * (tests/Concurrency/worker.php) that wait for one shared instant and then run the production
 * code path at the same time, each on its own database connection, with real commits. Sequential
 * loops cannot show these races and SQLite has neither row locks nor MySQL's REPEATABLE READ
 * snapshots, so the class is skipped there. Run with a throwaway database (it is migrated fresh):
 *
 *   DB_CONNECTION=mysql DB_DATABASE=<throwaway> php artisan test tests/Feature/Concurrency
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations, BuildsShop, BuildsPromotions;

    private const PAYMOB = ['environment' => 'sandbox', 'verify_with_api' => false, 'amount_factor' => 1, 'currency' => 'EGP',
        'sandbox' => ['hmac_secret' => 'concurrency-hmac']];

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency is verified on MySQL/MariaDB only (real row locks and REPEATABLE READ).');
        }
    }

    /**
     * Run the workers in parallel; returns each worker's decoded JSON line.
     *
     * @param  list<array{0: string, 1: array}>  $workers  [scenario, arguments]
     * @return list<array>
     */
    private function race(array $workers): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => DB::getDefaultConnection(), 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4',
        ];
        $startAt = sprintf('%.3f', microtime(true) + 6 + count($workers) * 0.08);

        $processes = array_map(function (array $worker) use ($env, $startAt) {
            $process = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $worker[0], $startAt, json_encode($worker[1])], base_path(), $env, null, 300);
            $process->start();

            return $process;
        }, $workers);

        return array_map(function (Process $process) {
            try {
                $process->wait();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Evidence for a stuck worker: what MySQL was doing (open transactions, lock waits).
                $transactions = collect(DB::select('SELECT trx_state, TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_s, trx_query FROM information_schema.innodb_trx'))
                    ->map(fn ($t) => "{$t->trx_state} {$t->age_s}s ".mb_substr((string) $t->trx_query, 0, 120))->implode("\n");
                $this->fail("Worker timed out.\nOpen transactions:\n".($transactions ?: '(none)')."\nWorker output:\n".$process->getOutput().$process->getErrorOutput());
            }
            $line = trim((string) collect(explode("\n", trim($process->getOutput())))->last());
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Worker output: '.$process->getOutput().$process->getErrorOutput());

            return $decoded;
        }, $processes);
    }

    private function statuses(array $results): array
    {
        $counts = array_count_values(array_map(fn ($r) => (string) $r['status'], $results));
        ksort($counts);

        return $counts;
    }

    private function noExceptions(array $results): void
    {
        $exceptions = array_values(array_filter($results, fn ($r) => $r['status'] === 'exception'));
        $this->assertSame([], $exceptions, 'No worker may fail with an unexpected exception (deadlock, lock wait timeout, 500).');
    }

    private function shoppers(int $count, $size, int $quantity = 1): array
    {
        return array_map(function () use ($size, $quantity) {
            $user = User::factory()->create();
            $this->putInCart($user, $size, $quantity);

            return $user;
        }, range(1, $count));
    }

    // ------------------------------------------------------------------ 1. last unit

    public function test_100_customers_buying_the_last_unit(): void
    {
        $size = $this->makeSize(price: 100, stock: 1);
        $users = $this->shoppers(100, $size);

        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $users));

        $this->noExceptions($results);
        $this->assertSame(['201' => 1, '422' => 99], $this->statuses($results));
        $this->assertSame(1, Order::count());
        $this->assertSame(0, $size->fresh()->stock);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
        $this->assertSame(0, DB::table('inventory_balances')->whereRaw('on_hand < reserved')->count());
    }

    public function test_two_customers_each_taking_7_of_10(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(20, $size, 7)));

        $this->noExceptions($results);
        $this->assertSame(['201' => 1, '422' => 19], $this->statuses($results));
        $this->assertSame(3, $size->fresh()->stock);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    public function test_one_customer_submitting_checkout_15_times_at_once(): void
    {
        $size = $this->makeSize(price: 100, stock: 50);
        [$user] = $this->shoppers(1, $size, 2);

        $results = $this->race(array_fill(0, 15, ['checkout', ['user_id' => $user->id]]));

        $this->noExceptions($results);
        $this->assertSame(['201' => 1, '422' => 14], $this->statuses($results));
        $this->assertSame(1, Order::count());
        $this->assertSame(48, $size->fresh()->stock);
    }

    // ------------------------------------------------------------------ 2-3. limited promotions

    public function test_30_customers_competing_for_one_promotion_use(): void
    {
        $size = $this->makeSize(price: 100, stock: 1000);
        $promotion = $this->promotion('percent', ['discount_value' => 10, 'usage_limit' => 1], [['product', $size->product_id]]);

        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(30, $size)));

        $this->noExceptions($results);
        $this->assertSame(1, PromotionRedemption::where('discount_rule_id', $promotion->id)->count());
        $this->assertEquals([90 => 1, 100 => 29], array_count_values(array_map(fn ($r) => (int) $r['total'], $results)));
    }

    public function test_30_customers_competing_for_three_flash_sale_units(): void
    {
        $size = $this->makeSize(price: 100, stock: 1000);
        $sale = $this->flashSaleOn($size, 50, ['quantity_limit' => 3]);

        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(30, $size)));

        $this->noExceptions($results);
        $this->assertSame(3, (int) FlashSaleRedemption::where('flash_sale_id', $sale->id)->sum('quantity'));
        $this->assertEquals([50 => 3, 100 => 27], array_count_values(array_map(fn ($r) => (int) $r['total'], $results)));
    }

    public function test_20_customers_competing_for_one_coupon_use_are_refused_not_overcharged(): void
    {
        $size = $this->makeSize(price: 100, stock: 1000);
        $coupon = $this->couponCode(['usage_limit' => 1]);

        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id, 'coupon' => $coupon->code]], $this->shoppers(20, $size)));

        $this->noExceptions($results);
        $this->assertSame(['201' => 1, '422' => 19], $this->statuses($results));
        $this->assertSame([1, 1], [(int) $coupon->fresh()->used_count, CouponRedemption::where('coupon_id', $coupon->id)->count()]);
    }

    // ------------------------------------------------------------------ 4. two payment attempts both paid

    public function test_two_payment_attempts_receiving_successful_callbacks_at_the_same_time(): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), self::PAYMOB)]);
        $user = User::factory()->create();
        $method = PaymentMethod::where('code', 'paymob')->firstOrFail();
        $order = Order::create(['user_id' => $user->id, 'payment_method' => 'paymob', 'payment_method_id' => $method->id, 'delivery_fee' => 0,
            'total_price' => 150, 'points_earned' => 0, 'status' => 'pending', 'currency_code' => 'EGP']);
        $attempt = fn (int $n, string $status) => Payment::create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'provider' => 'paymob',
            'attempt' => $n, 'internal_reference' => "PAY-CONC-{$order->id}-{$n}", 'amount' => 150, 'currency' => 'EGP', 'status' => $status,
            'provider_order_id' => (string) (7000 + $n), 'expires_at' => now()->addHour()]);
        $first = $attempt(1, 'canceled'); // superseded locally, still payable at the provider
        $second = $attempt(2, 'pending');

        $results = $this->race([
            ['webhook', ['payment_id' => $first->id, 'transaction_id' => 91001, 'paymob' => self::PAYMOB]],
            ['webhook', ['payment_id' => $second->id, 'transaction_id' => 91002, 'paymob' => self::PAYMOB]],
        ]);

        $this->noExceptions($results);
        $payments = Payment::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['paid', 'paid'], $payments->map(fn ($p) => $p->status->value)->all(), 'both captures are recorded');
        $duplicates = $payments->whereNotNull('duplicate_of_payment_id');
        $this->assertCount(1, $duplicates, 'exactly one is the duplicate, whichever callback won');
        $this->assertSame(1, Refund::where('payment_id', $duplicates->first()->id)->where('amount', 150)->count(), 'and it is refunded in full');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // ------------------------------------------------------------------ 5. refund requests

    public function test_ten_simultaneous_refund_requests_never_exceed_what_was_received(): void
    {
        $order = $this->makeOrder(User::factory()->create(), $this->makeSize(price: 100), status: 'completed');
        $payment = $this->payInCash($order);

        $results = $this->race(array_fill(0, 10, ['refund_request', ['payment_id' => $payment->id, 'amount_minor' => 3000]]));

        $this->noExceptions($results);
        $this->assertSame(['created' => 3, 'refused' => 7], $this->statuses($results));
        $this->assertSame(9000, Money::toMinor(Refund::where('payment_id', $payment->id)->sum('amount')));
    }

    // ------------------------------------------------------------------ 6. supplier payments

    public function test_ten_simultaneous_supplier_payments_against_one_invoice(): void
    {
        $supplier = Supplier::create(['code' => 'SUPC', 'name' => 'Supplier']);
        $invoice = SupplierInvoice::create(['supplier_id' => $supplier->id, 'number' => 'INV-C', 'invoice_date' => now()->toDateString(), 'amount' => 100]);
        $workers = array_map(fn ($i) => ['supplier_payment', ['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'amount' => 30, 'request_key' => "key-{$i}"]], range(1, 10));
        // The same form submitted three times at once.
        $workers[] = ['supplier_payment', ['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'amount' => 30, 'request_key' => 'key-1']];
        $workers[] = ['supplier_payment', ['supplier_id' => $supplier->id, 'invoice_id' => $invoice->id, 'amount' => 30, 'request_key' => 'key-1']];

        $results = $this->race($workers);

        $this->noExceptions($results);
        $this->assertLessThanOrEqual(10000, Money::toMinor(SupplierPayment::where('supplier_invoice_id', $invoice->id)->sum('amount')));
        $this->assertSame(3, SupplierPayment::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertLessThanOrEqual(1, SupplierPayment::where('request_key', 'key-1')->count());
    }

    // ------------------------------------------------------------------ 7. OTP guessing

    public function test_fifty_simultaneous_otp_guesses_compare_at_most_five(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $victim->forceFill(['otp_code' => Hash::make('424242'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0])->save();

        // 49 wrong guesses and the right code, each from its own IP, all at once.
        $workers = array_map(fn ($i) => ['otp', ['email' => 'victim@example.com', 'code' => sprintf('%06d', $i), 'ip' => "10.9.0.{$i}"]], range(1, 49));
        $workers[] = ['otp', ['email' => 'victim@example.com', 'code' => '424242', 'ip' => '10.9.1.1']];

        $results = $this->race($workers);

        $this->noExceptions($results);
        $this->assertLessThanOrEqual(5, (int) $victim->fresh()->otp_attempts, 'at most five guesses were ever compared');
        $succeeded = array_values(array_filter($results, fn ($r) => $r['status'] === 200));
        // The right code wins only if it was among the first five compared; no wrong code can.
        $this->assertTrue($succeeded === [] || $succeeded[0]['code'] === '424242');
        $this->assertLessThanOrEqual(1, count($succeeded));
    }

    // ------------------------------------------------------------------ admin stock edits

    public function test_concurrent_absolute_stock_edits_and_sales_do_not_overwrite_each_other(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $buyers = $this->shoppers(4, $size);

        // Eight admins set the stock to 7 while four customers each buy one unit.
        $workers = array_merge(
            array_fill(0, 8, ['set_stock', ['size_id' => $size->id, 'stock' => 7]]),
            array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $buyers),
        );

        $results = $this->race($workers);

        $this->noExceptions($results);
        $sold = count(array_filter($results, fn ($r) => $r['status'] === 201));
        // Each edit is applied to the current value under the SKU lock, so the last edit wins (7)
        // minus the sales that came after it; never 10 - 8 x 3 (edits computed from a stale value).
        $this->assertGreaterThanOrEqual(7 - $sold, $size->fresh()->stock);
        $this->assertLessThanOrEqual(7, $size->fresh()->stock);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    // ------------------------------------------------------------------ deadlocks: cancel vs courier event

    public function test_cancellations_and_delivery_events_on_the_same_orders_do_not_deadlock(): void
    {
        $size = $this->makeSize(price: 100, stock: 100);
        $workers = [];

        foreach (range(1, 12) as $i) {
            $user = User::factory()->create();
            $this->putInCart($user, $size, 1);
            $response = app(CheckoutService::class)->checkout($user, ['address_id' => -1, 'payment_method' => 'cash'], PaymentMethod::where('code', 'cash')->firstOrFail(), null);
            $order = Order::findOrFail($response->getData(true)['order_id']);
            $shipment = Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'cod_amount' => $order->total_price]);
            $workers[] = ['cancel', ['order_id' => $order->id]];
            $workers[] = ['deliver', ['shipment_id' => $shipment->id]];
        }

        $results = $this->race($workers);

        $this->noExceptions($results);
        foreach (Order::with('payments')->get() as $order) {
            $this->assertContains($order->status, ['canceled', 'completed']);
            $payment = $order->payments->first();
            $this->assertSame($order->status === 'completed' ? 'paid' : 'canceled', $payment->status->value, "order {$order->id}: payment follows the winner");
        }
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }
}
