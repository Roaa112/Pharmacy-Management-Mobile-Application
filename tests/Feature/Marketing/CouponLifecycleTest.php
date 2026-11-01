<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Orders\Services\ReturnDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Coupon scope, limits and the reserve → consume / release lifecycle. */
class CouponLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;
    private ProductSize $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->size = $this->makeSize(price: 100, stock: 50);
    }

    private function orderWith(Coupon $coupon): \Illuminate\Testing\TestResponse
    {
        $this->add($this->size, 1);

        return $this->checkout(['coupon_code' => $coupon->code])->assertCreated();
    }

    public function test_checkout_reserves_completion_consumes_and_cancellation_releases(): void
    {
        $coupon = $this->couponCode(['usage_limit' => 1]);

        $id = $this->orderWith($coupon)->assertJsonPath('coupon_discount', 10)->json('order_id');
        $redemption = CouponRedemption::where('order_id', $id)->sole();
        $this->assertSame(CouponRedemption::RESERVED, $redemption->status);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame($coupon->code, Order::find($id)->coupon_code);

        // Used up while the first order is open.
        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertStatus(400)->assertJsonPath('code', 'used_up');

        app(OrderWorkflow::class)->transition(Order::find($id), OrderStatus::Canceled, OrderWorkflow::ACTOR_SYSTEM);
        $this->assertSame(CouponRedemption::RELEASED, $redemption->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->used_count);

        // Given back: usable again, and this time the order completes.
        $second = $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('coupon_discount', 10)->json('order_id');
        app(OrderWorkflow::class)->transition(Order::find($second), OrderStatus::Completed, OrderWorkflow::ACTOR_SYSTEM);
        $this->assertSame(CouponRedemption::CONSUMED, CouponRedemption::where('order_id', $second)->value('status'));
    }

    public function test_with_the_release_setting_off_a_canceled_order_keeps_its_coupon_use(): void
    {
        Setting::current()->update(['coupon_release_on_cancel' => false]);
        $coupon = $this->couponCode(['usage_limit' => 1]);
        $id = $this->orderWith($coupon)->json('order_id');

        app(OrderWorkflow::class)->transition(Order::find($id), OrderStatus::Canceled, OrderWorkflow::ACTOR_SYSTEM);

        $this->assertSame(CouponRedemption::RESERVED, CouponRedemption::where('order_id', $id)->value('status'));
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_refund_after_delivery_does_not_give_the_use_back(): void
    {
        $coupon = $this->couponCode(['usage_limit' => 5]);
        $id = $this->orderWith($coupon)->json('order_id');
        $order = Order::find($id);
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, OrderWorkflow::ACTOR_SYSTEM);

        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $id, 'reason' => 'x', 'refund_amount' => 90]);
        app(ReturnDecisionService::class)->accept($return->id, 90, [], null);

        $this->assertSame(CouponRedemption::CONSUMED, CouponRedemption::where('order_id', $id)->value('status'));
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_the_limit_is_rechecked_at_checkout_so_a_second_order_cannot_exceed_it(): void
    {
        // Two customers previewed the last use; whoever checks out second gets no discount.
        $coupon = $this->couponCode(['usage_limit' => 1]);
        $other = User::factory()->create();

        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk();
        Sanctum::actingAs($other);
        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk();

        Sanctum::actingAs($this->user);
        $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('discount', 10);
        Sanctum::actingAs($other);
        $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('discount', 0)->assertJsonPath('total_price', 100);

        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->whereIn('status', CouponRedemption::COUNTING)->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_the_app_can_require_the_previewed_total_and_nothing_is_ordered_if_it_changed(): void
    {
        $coupon = $this->couponCode(['usage_limit' => 1]);
        $this->add($this->size, 1);
        $preview = $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk()->json('total_after_discount');
        $coupon->update(['used_count' => 1]); // someone else took the last use

        $this->checkout(['coupon_code' => $coupon->code, 'expected_total' => $preview])->assertStatus(409)->assertJsonPath('code', 'pricing_changed')
            ->assertJsonPath('pricing.total', 100);
        $this->assertSame(0, Order::count());
    }

    public function test_per_customer_limit_expiry_and_inactive_coupons(): void
    {
        $coupon = $this->couponCode(['per_user_limit' => 1]);
        $this->orderWith($coupon);
        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertStatus(400)->assertJsonPath('code', 'already_used');

        $expired = $this->couponCode(['start_at' => now()->subWeek(), 'end_at' => now()->subDays(2)]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $expired->code])->assertStatus(400)->assertJsonPath('code', 'expired');

        $off = $this->couponCode(['is_active' => false]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $off->code])->assertStatus(400)->assertJsonPath('code', 'invalid');
        $this->checkout(['coupon_code' => $off->code])->assertCreated()->assertJsonPath('discount', 0);
    }

    public function test_first_order_only_and_specific_customers(): void
    {
        $first = $this->couponCode(['first_order_only' => true]);
        $this->orderWith($first)->assertJsonPath('discount', 10);
        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $first->code])->assertStatus(400)->assertJsonPath('code', 'first_order_only');

        $private = $this->couponCode(['customer_scope' => 'specific']);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $private->code])->assertStatus(400)->assertJsonPath('code', 'customer_not_eligible');
        $private->customers()->attach($this->user->id);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $private->code])->assertOk();
    }

    public function test_product_and_category_scope_with_exclusions_and_minimum_on_eligible_items(): void
    {
        $category = Category::create(['name_ar' => 'ف', 'name_en' => 'Vitamins', 'image' => 'c.png']);
        $vitamin = $this->makeSize(price: 80, stock: 10);
        $vitamin->product->update(['category_id' => $category->id]);
        $excluded = $this->makeSize(price: 60, stock: 10);
        $excluded->product->update(['category_id' => $category->id]);

        $coupon = $this->couponCode(['discount_value' => 50, 'min_order_amount' => 50]);
        $coupon->targets()->create(['target_type' => 'category', 'target_id' => $category->id]);
        $coupon->targets()->create(['target_type' => 'product', 'target_id' => $excluded->product_id, 'is_excluded' => true]);

        $this->add($this->size, 1);     // not in the category
        $this->add($excluded, 1);       // excluded
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertStatus(400)->assertJsonPath('code', 'no_eligible_items');

        $this->add($vitamin, 1);
        // 50% of the vitamin only (80) = 40; delivery not included (pickup preview).
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk()->assertJsonPath('discount', 40);
        $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('discount', 40)->assertJsonPath('total_price', 200);
    }

    public function test_a_fixed_coupon_never_exceeds_what_it_applies_to(): void
    {
        $coupon = $this->couponCode(['type' => 'fixed', 'discount_value' => 500]);
        $this->add($this->size, 1);
        $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('total_price', 0)->assertJsonPath('discount', 100);
    }

    public function test_deleting_a_coupon_archives_it_and_orders_keep_it(): void
    {
        $coupon = $this->couponCode();
        $id = $this->orderWith($coupon)->json('order_id');

        $coupon->delete();

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
        $this->assertSame($coupon->code, Order::find($id)->coupon_code);
        $this->assertSame($coupon->code, CouponRedemption::where('order_id', $id)->first()->coupon->code);
        $this->add($this->size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertStatus(400);
    }
}
