<?php

namespace Tests\Feature\Concurrency;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentStatusHistory;
use App\Models\PromotionRedemption;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Inventory\Services\TransferService;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\Concerns\RunsRaces;
use Tests\TestCase;

/**
 * Final audit races, real processes against MySQL (see RunsRaces). Run with a throwaway database:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=<throwaway> php artisan test tests/Feature/Concurrency
 */
class AdversarialConcurrencyTest extends TestCase
{
    use DatabaseMigrations, BuildsShop, BuildsPromotions, RunsRaces;

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

    private function cleanLedger(): void
    {
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
        $this->assertSame(0, InventoryBalance::whereRaw('on_hand < reserved OR on_hand < 0 OR reserved < 0 OR quarantined < 0 OR in_transit < 0')->count());
        $this->assertSame(0, DB::table('batch_balances')->whereRaw('on_hand < reserved OR on_hand < 0 OR reserved < 0')->count());
    }

    private function placeCodOrder(User $user, $size, int $quantity = 1): Order
    {
        $this->putInCart($user, $size, $quantity);
        $response = app(CheckoutService::class)->checkout($user, ['address_id' => -1, 'payment_method' => 'cash'], PaymentMethod::where('code', 'cash')->firstOrFail(), null);
        $this->assertSame(201, $response->getStatusCode());

        return Order::findOrFail($response->getData(true)['order_id']);
    }

    private function paidOnlineOrder(float $amount = 150): Payment
    {
        $method = PaymentMethod::where('code', 'paymob')->firstOrFail();
        $order = Order::create(['user_id' => User::factory()->create()->id, 'payment_method' => 'paymob', 'payment_method_id' => $method->id, 'delivery_fee' => 0,
            'total_price' => $amount, 'points_earned' => 0, 'status' => 'pending', 'currency_code' => 'EGP']);

        return Payment::create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'provider' => 'paymob', 'internal_reference' => "PAY-ADV-{$order->id}",
            'amount' => $amount, 'currency' => 'EGP', 'status' => 'pending', 'provider_order_id' => (string) (8000 + $order->id), 'expires_at' => now()->addHour()]);
    }

    // ------------------------------------------------------------------ inventory

    public function test_100_customers_competing_for_10_units(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(100, $size)));

        $this->noExceptions($results);
        $this->assertSame(['201' => 10, '422' => 90], $this->statuses($results));
        $this->assertSame([10, 0], [Order::count(), $size->fresh()->stock]);
        $this->cleanLedger();
    }

    public function test_20_customers_competing_for_7_units(): void
    {
        $size = $this->makeSize(price: 100, stock: 7);
        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(20, $size)));

        $this->noExceptions($results);
        $this->assertSame(['201' => 7, '422' => 13], $this->statuses($results));
        $this->assertSame([7, 0], [Order::count(), $size->fresh()->stock]);
        $this->cleanLedger();
    }

    public function test_cancellations_releasing_stock_while_new_customers_check_out(): void
    {
        $size = $this->makeSize(price: 100, stock: 5);
        $held = array_map(fn () => $this->placeCodOrder(User::factory()->create(), $size), range(1, 5));
        $this->assertSame(0, $size->fresh()->stock);

        $results = $this->race(array_merge(
            array_map(fn (Order $o) => ['cancel', ['order_id' => $o->id]], $held),
            array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(10, $size)),
        ));

        $this->noExceptions($results);
        $sold = count(array_filter($results, fn ($r) => $r['status'] === 201));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r['status'] === 'canceled')));
        $this->assertLessThanOrEqual(5, $sold, 'released units are sold at most once');
        $this->assertSame(5 - $sold, $size->fresh()->stock);
        $this->cleanLedger();
    }

    public function test_a_transfer_dispatch_racing_sales_of_the_same_sku(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $main = Warehouse::default();
        $branch = Warehouse::create(['code' => 'BR2', 'name' => 'Branch', 'is_active' => true, 'is_sellable' => true]);
        $transfers = app(TransferService::class);
        $transfer = $transfers->approve($transfers->request($main->id, $branch->id, [$size->id => 6], null, null), null);

        $results = $this->race(array_merge([['transfer_dispatch', ['transfer_id' => $transfer->id]]],
            array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(10, $size))));

        $this->noExceptions($results);
        $sold = count(array_filter($results, fn ($r) => $r['status'] === 201));
        $moved = StockTransfer::findOrFail($transfer->id)->status === StockTransfer::IN_TRANSIT ? 6 : 0;
        $mainBalance = InventoryBalance::where(['warehouse_id' => $main->id, 'product_size_id' => $size->id])->sole();
        $this->assertSame(10, $mainBalance->on_hand + $moved, 'units are conserved: on hand in MAIN + in transit');
        $this->assertLessThanOrEqual($mainBalance->on_hand, $mainBalance->reserved);
        $this->assertSame($sold, (int) $mainBalance->reserved);
        $this->cleanLedger();
    }

    public function test_a_stock_count_completing_while_customers_reserve(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $counts = app(\App\Modules\Inventory\Services\StockCountService::class);
        $count = $counts->record($counts->start(Warehouse::default()->id, null, null), [$size->id => 6]);

        $results = $this->race(array_merge([['count_complete', ['count_id' => $count->id]]],
            array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(8, $size))));

        $this->noExceptions($results);
        $balance = InventoryBalance::where('product_size_id', $size->id)->sole();
        $sold = count(array_filter($results, fn ($r) => $r['status'] === 201));
        $counted = $count->fresh()->status === StockCount::COMPLETED;
        // Counted first: 6 on hand, at most 6 sold. Sales first: the count is refused once reservations exceed 6.
        $this->assertSame($counted ? 6 : 10, (int) $balance->on_hand);
        $this->assertSame($sold, (int) $balance->reserved);
        $this->assertLessThanOrEqual($balance->on_hand, $balance->reserved);
        $this->cleanLedger();
    }

    // ------------------------------------------------------------------ promotions

    public function test_30_customers_competing_for_three_promotion_uses(): void
    {
        $size = $this->makeSize(price: 100, stock: 1000);
        $promotion = $this->promotion('percent', ['discount_value' => 10, 'usage_limit' => 3], [['product', $size->product_id]]);

        $results = $this->race(array_map(fn ($u) => ['checkout', ['user_id' => $u->id]], $this->shoppers(30, $size)));

        $this->noExceptions($results);
        $this->assertSame(['201' => 30], $this->statuses($results));
        $this->assertSame(3, PromotionRedemption::where('discount_rule_id', $promotion->id)->count());
        $this->assertEquals([90 => 3, 100 => 27], array_count_values(array_map(fn ($r) => (int) $r['total'], $results)));
    }

    // ------------------------------------------------------------------ orders

    public function test_customer_cancellations_racing_warehouse_dispatch(): void
    {
        Setting::current()->update(['customer_can_cancel_pending' => true]);
        $size = $this->makeSize(price: 100, stock: 100);
        $workers = [];
        $orders = [];

        foreach (range(1, 15) as $i) {
            $user = User::factory()->create();
            $order = $this->placeCodOrder($user, $size);
            $orders[] = $order;
            // The customer's HTTP request is slower to reach the lock; the dispatch starts 0-450 ms
            // later, so both sides win some of the races.
            $workers[] = ['customer_cancel', ['order_id' => $order->id, 'token' => $user->createToken('race')->plainTextToken]];
            $workers[] = ['dispatch', ['order_id' => $order->id, 'delay_ms' => ($i % 4) * 150]];
        }

        $results = $this->race($workers);

        $this->noExceptions($results);
        $this->assertGreaterThan(0, count(array_filter($results, fn ($r) => $r['status'] === 200)), 'some customers won the race');
        $this->assertGreaterThan(0, count(array_filter($results, fn ($r) => $r['status'] === 'dispatched')), 'some dispatches won the race');
        foreach ($orders as $order) {
            $order->refresh();
            $this->assertContains($order->status, ['canceled', 'on_delivery']);
            // The customer may only ever cancel a pending order, whichever request won.
            $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->where('actor_type', OrderWorkflow::ACTOR_CUSTOMER)
                ->where('to_status', 'canceled')->where('from_status', '!=', 'pending')->count(), "order {$order->id}");
            $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->whereIn('to_status', ['canceled', 'on_delivery'])->count());
        }
        $cancels = array_values(array_filter($results, fn ($r) => array_key_exists('status', $r) && is_int($r['status'])));
        $this->assertSame([], array_values(array_diff(array_unique(array_column($cancels, 'status')), [200, 422])));
        $this->cleanLedger();
    }

    // ------------------------------------------------------------------ payments

    public function test_five_admins_paying_out_the_same_refund_call_the_provider_once(): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), self::PAYMOB)]);
        $payment = $this->paidOnlineOrder();
        $payment->update(['status' => 'paid', 'provider_transaction_id' => '777001', 'paid_at' => now()]);
        $refund = app(RefundService::class)->requestForPayment($payment->fresh(), 5000, 'Customer request', null);

        $admins = array_map(fn ($i) => \App\Models\Admin::create(['name' => "Cashier {$i}", 'email' => "cashier{$i}@example.com", 'password' => 'secret-password'])->id, range(1, 5));

        $results = $this->race(array_map(fn ($id) => ['process_refund', ['refund_id' => $refund->id, 'admin_id' => $id, 'paymob' => self::PAYMOB]], $admins));

        $this->noExceptions($results);
        $this->assertSame(1, array_sum(array_column($results, 'calls')), 'Paymob is asked to refund exactly once');
        $this->assertSame(['refused' => 4, 'succeeded' => 1], $this->statuses($results));
        $this->assertSame([Refund::SUCCEEDED, 5000, 'partially_refunded'], [$refund->fresh()->status, Money::toMinor($payment->fresh()->refunded_amount), $payment->fresh()->status->value]);
    }

    public function test_duplicate_callbacks_and_provider_inquiries_for_one_transaction_apply_it_once(): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), self::PAYMOB)]);
        $payment = $this->paidOnlineOrder();
        $transaction = [
            'id' => 555001, 'amount_cents' => $payment->amountMinor(), 'created_at' => now()->toIso8601String(), 'currency' => 'EGP', 'error_occured' => false,
            'has_parent_transaction' => false, 'integration_id' => 1, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'owner' => 1, 'pending' => false, 'success' => true,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'order' => ['id' => (int) $payment->provider_order_id, 'merchant_order_id' => $payment->internal_reference],
        ];

        $results = $this->race(array_merge(
            array_fill(0, 8, ['webhook', ['payment_id' => $payment->id, 'transaction_id' => 555001, 'paymob' => self::PAYMOB]]),
            array_fill(0, 4, ['refresh_payment', ['payment_id' => $payment->id, 'transaction' => $transaction, 'paymob' => self::PAYMOB]]),
        ));

        $this->noExceptions($results);
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame(1, PaymentStatusHistory::where('payment_id', $payment->id)->where('to_status', 'paid')->count(), 'paid exactly once');
        $this->assertSame(1, PaymentEvent::where('idempotency_key', 'paymob:txn:555001:success')->count());
        $this->assertSame(0, Refund::count());
        $this->assertSame('paid', $payment->order->fresh()->payment_status);
    }

    public function test_expiry_racing_a_late_successful_callback_never_keeps_money_for_a_canceled_order(): void
    {
        config(['services.paymob' => array_replace_recursive(config('services.paymob'), self::PAYMOB)]);
        $size = $this->makeSize(price: 150, stock: 6);
        $runs = [];

        foreach (range(1, 6) as $i) {
            $payment = $this->paidOnlineOrder();
            $payment->update(['expires_at' => now()->subMinute()]);
            $line = \App\Models\OrderItem::create(['order_id' => $payment->order_id, 'product_id' => $size->product_id, 'product_size_id' => $size->id,
                'quantity' => 1, 'price_at_time' => 150, 'original_price' => 150, 'total_price' => 150]);
            app(\App\Modules\Inventory\Services\InventoryService::class)->reserve($line);
            $runs[] = $payment;
        }

        $workers = [['expire', ['paymob' => self::PAYMOB]]];
        foreach ($runs as $n => $payment) {
            // Staggered so some callbacks arrive before the expiry job reaches their payment and some after.
            $workers[] = ['webhook', ['payment_id' => $payment->id, 'transaction_id' => 660000 + $n, 'paymob' => self::PAYMOB, 'delay_ms' => $n * 120]];
        }
        $results = $this->race($workers);

        $this->noExceptions($results);
        $canceled = Order::whereIn('id', array_map(fn ($p) => $p->order_id, $runs))->where('status', 'canceled')->count();
        $this->assertGreaterThan(0, $canceled, 'some orders expired before their callback');
        $this->assertLessThan(6, $canceled, 'some callbacks arrived first');
        foreach ($runs as $payment) {
            $payment->refresh();
            $order = $payment->order->fresh();
            $this->assertSame('paid', $payment->status->value, 'the captured money is always recorded');
            $refunded = Refund::where('payment_id', $payment->id)->sum('amount');
            // Paid and canceled = refund queued for everything; paid and alive = nothing refunded.
            $this->assertSame($order->status === 'canceled' ? 15000 : 0, Money::toMinor($refunded), "order {$order->id} is {$order->status}");
        }
        $this->cleanLedger();
    }
}
