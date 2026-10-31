<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class FinancialTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function checkout(array $overrides = [])
    {
        return $this->postJson('/api/user/orders', array_merge(['address_id' => -1, 'payment_method' => 'cash'], $overrides));
    }

    private function discountRule(string $type, $value, string $targetType, int $targetId): void
    {
        $ruleId = DB::table('discount_rules')->insertGetId([
            'discount_type' => $type, 'discount_value' => $value, 'applies_to_type' => $targetType,
            'applies_to_id' => $targetId, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        DB::table('discount_rule_targets')->insert(['discount_rule_id' => $ruleId, 'target_type' => $targetType, 'target_id' => $targetId]);
    }

    public function test_multiple_items_with_decimals_add_up_exactly(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 19.99), 3);
        $this->putInCart($this->user, $this->makeSize(price: 0.10), 3);
        $this->putInCart($this->user, $this->makeSize(price: 0.20), 1);

        $this->checkout()->assertCreated();

        // 19.99*3 + 0.10*3 + 0.20 = 60.47
        $this->assertEquals(60.47, Order::sole()->total_price);
        $this->assertEquals(59.97, DB::table('order_items')->where('quantity', 3)->orderByDesc('price_at_time')->value('total_price'));
    }

    public function test_percentage_discount_rounds_half_up_like_before(): void
    {
        $size = $this->makeSize(price: 0.10);
        $this->discountRule('percent', 15, 'product', $size->product_id);
        $this->putInCart($this->user, $size, 10);

        $this->checkout()->assertCreated();

        // 0.10 * 0.85 = 0.085 -> 0.09 per unit, 0.90 for ten.
        $this->assertDatabaseHas('order_items', ['product_size_id' => $size->id, 'price_at_time' => 0.09, 'total_price' => 0.90]);
    }

    public function test_fixed_discount_larger_than_the_price_makes_it_free_not_negative(): void
    {
        $size = $this->makeSize(price: 5);
        $this->discountRule('fixed', 8, 'product', $size->product_id);
        $this->putInCart($this->user, $size, 2);

        $this->checkout()->assertCreated();

        $this->assertEquals(0, Order::sole()->total_price);
    }

    public function test_coupon_applies_to_subtotal_plus_delivery_and_can_reach_zero(): void
    {
        $this->makeDeliveryPrice('Baghdad', 5);
        $address = $this->makeAddress($this->user);
        $this->putInCart($this->user, $this->makeSize(price: 45), 1);
        Coupon::create(['code' => 'HALF', 'discount_value' => 50, 'is_active' => true]);

        $this->checkout(['address_id' => $address->id, 'coupon_code' => 'HALF'])->assertCreated();
        $this->assertEquals(25, Order::sole()->total_price); // (45 + 5) * 50%

        Coupon::create(['code' => 'FREE', 'discount_value' => 100, 'is_active' => true]);
        $this->putInCart($this->user, $this->makeSize(price: 45), 1);
        $this->checkout(['address_id' => $address->id, 'coupon_code' => 'FREE'])->assertCreated();
        $this->assertEquals(0, Order::latest('id')->first()->total_price);
    }

    public function test_coupon_percentage_is_rounded_to_the_cent(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 0.15), 1);
        Coupon::create(['code' => 'ODD', 'discount_value' => 12.5, 'is_active' => true]);

        $this->checkout(['coupon_code' => 'ODD'])->assertCreated();

        // discount 0.01875 -> 0.02, total 0.13
        $this->assertEquals(0.13, Order::sole()->total_price);
    }

    public function test_client_cannot_send_prices_or_totals(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 30), 1);

        $this->checkout(['total_price' => 1, 'delivery_fee' => 0, 'points_earned' => 9999, 'status' => 'completed'])->assertCreated();

        $order = Order::sole();
        $this->assertEquals(30, $order->total_price);
        $this->assertSame(0, (int) $order->points_earned);
        $this->assertSame('pending', $order->status);
    }

    public function test_loyalty_points_use_exact_division(): void
    {
        Setting::create(['spend_x' => '0.10', 'get_y' => '2']);
        $this->putInCart($this->user, $this->makeSize(price: 0.70), 1);

        $response = $this->checkout()->assertCreated();

        // 0.70 / 0.10 = 7 exactly; float division gives 6.999... and floor() made it 6.
        $this->assertEquals(14, $response->json('points_earned'));
        $this->assertSame(14, (int) Order::sole()->points_earned);
    }

    public function test_cart_totals_are_exact(): void
    {
        $this->putInCart($this->user, $this->makeSize(price: 0.10), 1);
        $this->putInCart($this->user, $this->makeSize(price: 0.20), 1);

        $this->assertSame(0.3, $this->getJson('/api/user/cart')->assertOk()->json('total_price'));
    }

    // ---------- refunds ----------

    private function returnRequest(Order $order, $amount)
    {
        return $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => $amount]);
    }

    public function test_partial_refunds_cannot_add_up_to_more_than_was_paid(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 100), status: 'completed');

        $this->returnRequest($order, 60)->assertCreated();
        $this->returnRequest($order, 40)->assertCreated();
        $this->returnRequest($order, 0.01)->assertStatus(422);

        $this->assertSame(2, ReturnRequest::count());
    }

    public function test_rejected_or_canceled_requests_do_not_use_up_the_refundable_amount(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 100), status: 'completed');
        $this->returnRequest($order, 100)->assertCreated();
        ReturnRequest::sole()->update(['status' => 'rejected']);

        $this->returnRequest($order, 100)->assertCreated();
    }

    public function test_full_refund_zero_and_negative_amounts(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 100), status: 'completed');

        $this->returnRequest($order, -1)->assertStatus(422);
        $this->returnRequest($order, 'abc')->assertStatus(422);
        $this->returnRequest($order, 0)->assertCreated();
        $this->returnRequest($order, 100)->assertCreated();
    }

    public function test_canceled_orders_cannot_be_returned(): void
    {
        $order = $this->makeOrder($this->user, $this->makeSize(price: 100), status: 'canceled');

        $this->returnRequest($order, 10)->assertStatus(422);
    }

    public function test_return_can_only_be_decided_once(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $order = $this->makeOrder($this->user, $this->makeSize(price: 100), status: 'completed');
        $this->returnRequest($order, 50)->assertCreated();
        $return = ReturnRequest::sole();

        $this->actingAs($admin, 'admin')->from('/dashboard/returns')->post("/dashboard/returns/{$return->id}/accept")->assertSessionHas('success');
        $this->actingAs($admin, 'admin')->from('/dashboard/returns')->post("/dashboard/returns/{$return->id}/reject")->assertSessionHas('error');

        $this->assertSame('accepted', $return->fresh()->status);
    }
}
