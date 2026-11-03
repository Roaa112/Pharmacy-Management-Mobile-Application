<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\LoyaltyPointReversal;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Orders\Services\ReturnDecisionService;
use App\Modules\Points\Services\InsufficientPoints;
use App\Modules\Points\Services\LoyaltyLedger;
use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Points\Services\RewardRedemptionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class LoyaltyProgrammeTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $customer;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->update(['spend_x' => '10', 'get_y' => '1', 'points_validity_days' => 30]);
        $this->customer = User::factory()->create();
        $this->admin = Admin::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => 'secret-password']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    private function balance(?User $user = null): int
    {
        return app(LoyaltyLedger::class)->balance(($user ?? $this->customer)->id);
    }

    private function checkout(float $price = 100, array $extra = [], int $status = 201)
    {
        Sanctum::actingAs($this->customer);
        \App\Models\Cart::where('user_id', $this->customer->id)->delete(); // a refused checkout keeps its cart
        $this->putInCart($this->customer, $this->makeSize(price: $price, stock: 5), 1);
        $response = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'] + $extra)->assertStatus($status);

        return $status === 201 ? Order::findOrFail($response->json('order_id')) : $response;
    }

    private function complete(Order $order): Order
    {
        return app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, 'admin', $this->admin->id);
    }

    /** A usable lot of $points (a bonus), optionally expiring at $expiresAt. */
    private function lot(int $points, ?Carbon $expiresAt = null, ?User $user = null): Entry
    {
        $lot = $this->loyalty()->adjust($user ?? $this->customer, $points, 'Test lot', $this->admin, Entry::BONUS);
        if ($expiresAt) {
            $lot->forceFill(['expires_at' => $expiresAt])->save();
        }

        return $lot->fresh();
    }

    private function reward(array $attributes = []): LoyaltyReward
    {
        return LoyaltyReward::create($attributes + ['code' => 'RW-'.uniqid(), 'type' => 'voucher', 'title' => 'Voucher', 'points_required' => 100, 'stock' => 5, 'is_active' => true]);
    }

    private function assertLedgerBalances(?User $user = null): void
    {
        $id = ($user ?? $this->customer)->id;
        $this->assertSame((int) Entry::where('user_id', $id)->sum('points'), (int) Entry::lots()->where('user_id', $id)->sum('remaining_points'), 'Σ entries = Σ lots');
        $this->assertGreaterThanOrEqual(0, (int) Entry::lots()->where('user_id', $id)->min('remaining_points'));
    }

    private function refused(callable $action): string
    {
        try {
            $action();
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $this->fail('Expected the action to be refused.');
    }

    // ---------------------------------------------------------------- earning

    public function test_points_are_earned_by_the_settings_and_become_usable_on_completion(): void
    {
        $order = $this->checkout(100);
        $lot = Entry::where('order_id', $order->id)->sole();

        $this->assertSame([10, 'pending', null], [$lot->points, $lot->status, $lot->expires_at]);
        $summary = $this->loyalty()->summary($this->customer->id);
        $this->assertSame([0, 10], [$summary['available'], $summary['pending']]);

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->complete($order);
        $lot->refresh();

        $this->assertSame(['active', '2026-12-01 10:00:00'], [$lot->status, $lot->expires_at->toDateTimeString()]);
        $this->assertSame(10, $this->balance());
    }

    public function test_earning_rules_minimum_maximum_cod_and_switch_off(): void
    {
        Setting::current()->update(['loyalty_min_order_amount' => 50]);
        $this->assertSame(0, (int) $this->checkout(40)->points_earned);

        Setting::current()->update(['loyalty_min_order_amount' => 0, 'loyalty_max_points_per_order' => 7]);
        $this->assertSame(7, (int) $this->checkout(100)->points_earned);

        Setting::current()->update(['loyalty_max_points_per_order' => null, 'loyalty_earn_on_cod' => false]);
        $this->assertSame(0, (int) $this->checkout(100)->points_earned);

        Setting::current()->update(['loyalty_earn_on_cod' => true, 'loyalty_enabled' => false]);
        $this->assertSame(0, (int) $this->checkout(100)->points_earned);
    }

    public function test_activation_immediately_and_on_payment(): void
    {
        Setting::current()->update(['loyalty_activation' => 'immediate']);
        $this->checkout(200);
        $this->assertSame(20, $this->balance());

        Setting::current()->update(['loyalty_activation' => 'paid']);
        $order = $this->checkout(100);
        $this->assertSame(20, $this->balance());
        // Completing a cash order records the cash as collected: the payment makes the points usable.
        $this->complete($order);
        $this->assertSame(30, $this->balance());
    }

    // ---------------------------------------------------------------- expiry and FIFO

    public function test_fifo_redemption_uses_the_soonest_expiring_points_first(): void
    {
        $a = $this->lot(100, now()->addDays(7));
        $b = $this->lot(50, now()->addDays(27));
        $c = $this->lot(200, now()->addDays(47));

        $entry = app(LoyaltyLedger::class)->debit($this->customer->id, Entry::REDEEMED, 120, 'redeemed:test:1');

        $this->assertSame([0, 30, 200], [$a->fresh()->remaining_points, $b->fresh()->remaining_points, $c->fresh()->remaining_points]);
        $this->assertSame([[$a->id, 100], [$b->id, 20]], $entry->allocations()->orderBy('id')->get()->map(fn ($x) => [$x->lot_id, $x->points])->all());
        $this->assertSame(['depleted', 'active'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame(230, $this->balance());
        $this->assertLedgerBalances();
    }

    public function test_a_failed_redemption_rolls_back_completely(): void
    {
        $a = $this->lot(100);
        $this->lot(50);

        $this->expectException(InsufficientPoints::class);
        try {
            app(LoyaltyLedger::class)->debit($this->customer->id, Entry::REDEEMED, 200, 'redeemed:test:too-much');
        } finally {
            $this->assertSame(100, $a->fresh()->remaining_points);
            $this->assertSame(0, Entry::where('type', Entry::REDEEMED)->count());
        }
    }

    public function test_expired_points_cannot_be_redeemed_even_before_the_expiry_run(): void
    {
        $this->lot(100, now()->subMinute());
        $this->lot(30);

        $this->assertSame(30, $this->balance());
        $this->expectException(InsufficientPoints::class);
        app(LoyaltyLedger::class)->debit($this->customer->id, Entry::REDEEMED, 50, 'redeemed:test:2');
    }

    public function test_the_expiry_run_writes_off_lapsed_points_once_and_shows_them(): void
    {
        $old = $this->lot(100, now()->subDay());
        $this->lot(40, now()->addDays(3));

        $this->artisan('loyalty:record-expirations')->assertSuccessful();
        $this->artisan('loyalty:record-expirations')->assertSuccessful();

        $this->assertSame(1, Entry::where('type', Entry::EXPIRED)->count());
        $this->assertSame([0, 'expired'], [$old->fresh()->remaining_points, $old->fresh()->status]);
        $summary = $this->loyalty()->summary($this->customer->id);
        $this->assertSame([40, 40, 100], [$summary['available'], $summary['expiring_soon'], $summary['total_expired']]);
        $this->assertLedgerBalances();

        $this->actingAs($this->admin, 'admin')->get(route('dashboard.loyalty.expiring'))->assertOk()->assertSee('Expiration history');
    }

    // ---------------------------------------------------------------- rewards

    public function test_reward_redemption_takes_stock_and_points_and_records_everything(): void
    {
        $this->lot(250);
        $reward = $this->reward(['points_required' => 100, 'stock' => 3]);

        $redemption = app(RewardRedemptionService::class)->redeem($this->customer, $reward, 2);

        $this->assertSame([LoyaltyRedemption::PENDING, 200], [$redemption->status, $redemption->points]);
        $this->assertMatchesRegularExpression('/^RDM-\d{6}$/', $redemption->number);
        $this->assertSame([1, 2], [$reward->fresh()->stock, $reward->fresh()->redeemed_count]);
        $this->assertSame(50, $this->balance());
        $this->assertSame(-200, $redemption->ledgerEntry->points);
        $this->assertTrue(AdminAuditLog::where('action', 'loyalty.reward_redeemed')->where('subject_id', $redemption->id)->exists());
        $this->assertLedgerBalances();
    }

    public function test_reward_checks_refuse_and_change_nothing(): void
    {
        $this->lot(1000);
        $service = app(RewardRedemptionService::class);

        $this->refused(fn () => $service->redeem($this->customer, $this->reward(['is_active' => false])));
        $this->refused(fn () => $service->redeem($this->customer, $this->reward(['starts_at' => now()->addDay()])));
        $this->refused(fn () => $service->redeem($this->customer, $this->reward(['ends_at' => now()->subDay()])));
        $this->refused(fn () => $service->redeem($this->customer, $this->reward(['stock' => 1]), 2));
        $this->refused(fn () => $service->redeem($this->customer, $this->reward(['points_required' => 2000, 'stock' => null])));

        $perCustomer = $this->reward(['per_customer_limit' => 1, 'stock' => null]);
        $service->redeem($this->customer, $perCustomer);
        $this->assertStringContainsString('1', $this->refused(fn () => $service->redeem($this->customer, $perCustomer)));

        $total = $this->reward(['total_limit' => 1, 'stock' => null]);
        $other = User::factory()->create();
        $this->lot(500, null, $other);
        $service->redeem($other, $total);
        $this->refused(fn () => $service->redeem($this->customer, $total));

        $this->assertSame(1, LoyaltyRedemption::where('user_id', $this->customer->id)->count());
        $this->assertSame(900, $this->balance());
    }

    public function test_the_same_redemption_request_twice_spends_points_once(): void
    {
        $this->lot(300);
        $reward = $this->reward();
        Sanctum::actingAs($this->customer);

        $first = $this->postJson("/api/user/loyalty/rewards/{$reward->id}/redeem", [], ['Idempotency-Key' => 'tap-1'])->assertCreated();
        $this->postJson("/api/user/loyalty/rewards/{$reward->id}/redeem", [], ['Idempotency-Key' => 'tap-1'])->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, LoyaltyRedemption::count());
        $this->assertSame(200, $this->balance());
        $this->assertSame(4, $reward->fresh()->stock);
    }

    public function test_one_open_redemption_at_a_time_when_multiple_are_not_allowed(): void
    {
        Setting::current()->update(['loyalty_allow_multiple_redemptions' => false]);
        $this->lot(500);
        $service = app(RewardRedemptionService::class);
        $first = $service->redeem($this->customer, $this->reward());

        $this->refused(fn () => $service->redeem($this->customer, $this->reward()));

        $service->fulfil($first, $this->admin);
        $service->redeem($this->customer, $this->reward());
        $this->assertSame(2, LoyaltyRedemption::count());
    }

    public function test_cancelling_a_redemption_returns_stock_and_points_and_fulfilment_takes_product_stock(): void
    {
        $this->lot(300);
        $size = $this->makeSize(stock: 4);
        $product = $this->reward(['type' => 'product', 'product_size_id' => $size->id, 'product_id' => $size->product_id, 'stock' => 2]);
        $service = app(RewardRedemptionService::class);

        $redemption = $service->redeem($this->customer, $product);
        $cancelled = $service->cancel($redemption, $this->admin, 'Customer changed their mind');

        $this->assertSame([LoyaltyRedemption::CANCELLED, $this->admin->id, 'Customer changed their mind'], [$cancelled->status, $cancelled->cancelled_by, $cancelled->cancellation_reason]);
        $this->assertSame([2, 0], [$product->fresh()->stock, $product->fresh()->redeemed_count]);
        $this->assertSame(300, $this->balance());
        $this->refused(fn () => $service->cancel($cancelled, $this->admin, 'again'));

        $fulfilled = $service->fulfil($service->redeem($this->customer, $product), $this->admin);
        $this->assertSame(LoyaltyRedemption::FULFILLED, $fulfilled->status);
        $this->assertSame(3, (int) $size->fresh()->stock, 'the product left stock as a recorded movement');
        $this->assertTrue(\App\Models\StockMovement::where('product_size_id', $size->id)->where('type', 'adjustment')->exists());
        $this->refused(fn () => $service->cancel($fulfilled, $this->admin, 'too late'));
        $this->assertLedgerBalances();
    }

    public function test_uncollected_redemptions_expire_and_give_everything_back(): void
    {
        $this->lot(200);
        $reward = $this->reward();
        $redemption = app(RewardRedemptionService::class)->redeem($this->customer, $reward);
        $redemption->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('loyalty:record-expirations')->assertSuccessful();

        $this->assertSame(LoyaltyRedemption::EXPIRED, $redemption->fresh()->status);
        $this->assertSame([200, 5], [$this->balance(), $reward->fresh()->stock]);
    }

    // ---------------------------------------------------------------- cancellation and refunds

    public function test_cancelling_an_order_reverses_its_points_and_returns_points_used_on_it(): void
    {
        Setting::current()->update(['loyalty_point_value' => 0.5]);
        $this->lot(60);

        $order = $this->checkout(100, ['redeem_points' => 40]);
        $this->assertEquals(80, $order->total_price); // 40 points × 0.50
        $this->assertSame([40, 20], [(int) $order->points_redeemed, $this->balance()]);

        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, 'admin', $this->admin->id);

        $this->assertSame(60, $this->balance(), 'the 40 points used on the order came back');
        $this->assertSame(LoyaltyRedemption::CANCELLED, LoyaltyRedemption::where('order_id', $order->id)->sole()->status);
        $lot = Entry::lots()->where('order_id', $order->id)->where('type', Entry::EARNED)->sole();
        $this->assertSame(['void', 0], [$lot->status, $lot->remaining_points], 'the points it earned are void');
        $this->assertSame(1, Entry::where('type', Entry::CANCELLED)->count());

        // Cancelling is not repeatable, and the loyalty step is idempotent anyway.
        $this->loyalty()->onOrderCanceled($order->fresh(), $this->admin->id);
        $this->assertSame([60, 1], [$this->balance(), Entry::where('type', Entry::CANCELLED)->count()]);
        $this->assertLedgerBalances();
    }

    public function test_refunds_reverse_points_once_and_never_make_a_balance_negative(): void
    {
        $order = $this->complete($this->checkout(100)); // 10 points
        $return = ReturnRequest::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 50]);
        app(ReturnDecisionService::class)->accept($return->id, null, [], $this->admin->id);

        $reversal = LoyaltyPointReversal::sole();
        $this->assertSame(5, $reversal->points);
        $this->loyalty()->recordReversal($reversal); // a retry of the same refund
        $this->assertSame(1, Entry::where('type', Entry::REFUNDED)->count());
        $this->assertSame(5, $this->balance());
        $this->assertLedgerBalances();
    }

    public function test_points_already_spent_are_taken_from_other_points_then_recorded_as_owed(): void
    {
        $order = $this->complete($this->checkout(100)); // 10 points
        app(LoyaltyLedger::class)->debit($this->customer->id, Entry::REDEEMED, 10, 'redeemed:test:spent');
        $this->lot(3);

        $return = ReturnRequest::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 100]);
        app(ReturnDecisionService::class)->accept($return->id, null, [], $this->admin->id);

        $entry = Entry::where('type', Entry::REFUNDED)->sole();
        $this->assertSame([-3, 7], [$entry->points, $entry->metadata['shortfall']]);
        $this->assertSame(0, $this->balance());
        $this->assertSame('redeemed', LoyaltyPointReversal::sole()->points_status_at_reversal);
        $this->assertSame(7, $this->loyalty()->summary($this->customer->id)['owed']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------- checkout redemption

    public function test_checkout_redemption_rules(): void
    {
        $this->lot(500);

        $this->checkout(100, ['redeem_points' => 10], 422)->assertJsonPath('code', 'points_rejected'); // value 0: off

        Setting::current()->update(['loyalty_point_value' => 1, 'loyalty_min_redeem_points' => 20, 'loyalty_max_redeem_points_per_order' => 50]);
        $this->checkout(100, ['redeem_points' => 10], 422);                       // below the minimum
        $this->checkout(100, ['redeem_points' => 60], 422);                       // above the per-order cap
        $this->checkout(30, ['redeem_points' => 40], 422);                        // more than the products cost
        Setting::current()->update(['loyalty_allow_partial_redemption' => false]);
        $this->checkout(100, ['redeem_points' => 30], 422)->assertJsonPath('reason', __('points.must_redeem_all', ['max' => 50], 'ar'));

        $order = $this->checkout(100, ['redeem_points' => 50]);
        $this->assertEquals(50, $order->total_price);
        $this->assertEquals(50, $order->payments()->sole()->amount);
        $this->assertSame(450, $this->balance());
        $this->assertSame(LoyaltyRedemption::SOURCE_CHECKOUT, LoyaltyRedemption::where('order_id', $order->id)->sole()->source);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------- manual adjustments

    public function test_manual_adjustments_need_a_reason_never_go_below_zero_and_are_audited(): void
    {
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => 50, 'reason' => 'Goodwill'])->assertRedirect();
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => -20, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => -80, 'reason' => 'Mistake'])->assertSessionHas('error');
        $this->actingAs($this->admin, 'admin')->postJson(route('dashboard.loyalty.adjust', $this->customer), ['points' => -20, 'reason' => 'Correction'])
            ->assertCreated()->assertJsonPath('balance', 30);

        $this->assertSame([50, -20], Entry::where('type', Entry::ADJUSTED)->orderBy('id')->pluck('points')->all());
        $this->assertSame($this->admin->id, Entry::where('type', Entry::ADJUSTED)->first()->admin_id);
        $log = AdminAuditLog::where('action', 'loyalty.adjust')->latest('id')->first();
        $this->assertSame([-20, 'Correction', 50, 30], [$log->changes['points'], $log->changes['reason'], $log->changes['before']['balance'], $log->changes['after']['balance']]);
        $this->assertLedgerBalances();
    }

    public function test_a_double_submitted_adjustment_is_recorded_once(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => 25, 'reason' => 'Gift', 'request_key' => 'form-1']);
        }

        $this->assertSame(25, $this->balance());
    }

    // ---------------------------------------------------------------- API and screens

    public function test_customer_api_balance_history_filters_rewards_and_redemptions(): void
    {
        $this->lot(120);
        app(LoyaltyLedger::class)->debit($this->customer->id, Entry::REDEEMED, 20, 'redeemed:test:api');
        $reward = $this->reward(['points_required' => 50]);
        $this->reward(['is_active' => false]);
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/user/loyalty')->assertOk()->assertJsonPath('data.available', 100)->assertJsonPath('data.total_redeemed', 20)
            ->assertJsonPath('rules.earning.points_per_spend', 1);
        $this->getJson('/api/user/loyalty/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/user/loyalty/history?type=redeemed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.points', -20);
        $this->getJson('/api/user/loyalty/history?type=nonsense')->assertStatus(422);
        $this->getJson('/api/user/loyalty/history?from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/user/loyalty/rewards')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.affordable', true)->assertJsonPath('balance', 100);
        $this->getJson("/api/user/loyalty/rewards/{$reward->id}")->assertOk()->assertJsonPath('data.points_required', 50);

        $id = $this->postJson("/api/user/loyalty/rewards/{$reward->id}/redeem")->assertCreated()->assertJsonPath('balance', 50)->json('data.id');
        $this->getJson('/api/user/loyalty/redemptions')->assertOk()->assertJsonPath('data.0.id', $id);

        // Someone else's redemption is invisible.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/user/loyalty/redemptions/{$id}")->assertNotFound();
        $this->postJson("/api/user/loyalty/rewards/{$reward->id}/redeem")->assertStatus(422)->assertJsonPath('code', 'redemption_refused');
    }

    public function test_guests_and_customers_cannot_reach_loyalty_admin_endpoints(): void
    {
        $this->getJson('/api/user/loyalty')->assertUnauthorized();
        $this->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => 10, 'reason' => 'x'])->assertRedirect('/login');
        $this->actingAs($this->customer, 'web')->post(route('dashboard.loyalty.adjust', $this->customer), ['points' => 10, 'reason' => 'x'])->assertRedirect('/login');
        $this->assertSame(0, Entry::count());
    }

    public function test_admin_loyalty_screens_render(): void
    {
        $this->lot(80);
        $reward = $this->reward(['points_required' => 50]);
        app(RewardRedemptionService::class)->redeem($this->customer, $reward);

        foreach ([route('dashboard.loyalty.overview'), route('dashboard.loyalty.customers'), route('dashboard.loyalty.transactions', ['type' => 'bonus']),
            route('dashboard.loyalty.expiring'), route('dashboard.loyalty.settings.edit'), route('dashboard.loyalty.rewards.index'),
            route('dashboard.loyalty.rewards.create'), route('dashboard.loyalty.rewards.edit', $reward), route('dashboard.loyalty.redemptions.index'),
            route('dashboard.customers.show', $this->customer)] as $url) {
            $this->actingAs($this->admin, 'admin')->get($url)->assertOk();
        }

        $this->actingAs($this->admin, 'admin')->get(route('dashboard.customers.show', $this->customer))->assertSee('Total earned')->assertSee('Adjust points');
    }

    public function test_settings_and_rewards_are_saved_from_the_dashboard_and_audited(): void
    {
        $this->actingAs($this->admin, 'admin')->put(route('dashboard.loyalty.settings.update'), [
            'spend_x' => 20, 'get_y' => 2, 'loyalty_earn_basis' => 'paid', 'loyalty_activation' => 'paid', 'points_validity_days' => 90,
            'loyalty_expiry_warning_days' => 10, 'loyalty_point_value' => 0.25, 'loyalty_enabled' => 1, 'loyalty_earn_on_shipping' => 1,
        ])->assertSessionHasNoErrors();

        $s = Setting::current();
        $this->assertSame(['paid', 'paid', 90, true, false], [$s->loyalty_earn_basis, $s->loyalty_activation, (int) $s->points_validity_days, $s->loyalty_earn_on_shipping, $s->loyalty_earn_on_cod]);
        $this->assertTrue(AdminAuditLog::where('action', 'loyalty.settings')->exists());

        $this->actingAs($this->admin, 'admin')->put(route('dashboard.loyalty.settings.update'), ['loyalty_earn_basis' => 'gross', 'loyalty_activation' => 'never'])
            ->assertSessionHasErrors(['loyalty_earn_basis', 'loyalty_activation', 'spend_x']);

        $size = $this->makeSize();
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.rewards.store'), [
            'code' => 'rw-para', 'type' => 'product', 'title' => 'Paracetamol', 'product_size_id' => $size->id, 'points_required' => 100, 'stock' => 10, 'is_active' => 1,
        ])->assertRedirect();
        $reward = LoyaltyReward::where('code', 'RW-PARA')->sole();
        $this->assertSame($size->product_id, $reward->product_id);
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.rewards.store'), ['code' => 'RW-X', 'type' => 'product', 'title' => 'X', 'points_required' => 10])
            ->assertSessionHasErrors('product_size_id');

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.loyalty.rewards.toggle', $reward));
        $this->assertFalse($reward->fresh()->is_active);
        $this->actingAs($this->admin, 'admin')->delete(route('dashboard.loyalty.rewards.destroy', $reward));
        $this->assertSoftDeleted($reward);
        $this->assertSame(['loyalty.reward_create', 'loyalty.reward_deactivate', 'loyalty.reward_delete'],
            AdminAuditLog::where('action', 'like', 'loyalty.reward_%')->orderBy('id')->pluck('action')->all());
    }
}
