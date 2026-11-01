<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\ProductSize;
use App\Models\PromotionCampaign;
use App\Models\PromotionReward;
use App\Models\User;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Marketing dashboard: pages, validation, activation blocking, lifecycle actions, preview. */
class MarketingDashboardTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private Admin $admin;
    private ProductSize $size;
    private ProductSize $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create(['name' => 'Marketer', 'email' => 'm@example.com', 'password' => 'secret-password']);
        $this->size = $this->makeSize(price: 100, stock: 10);
        $this->gift = $this->makeSize(price: 20, stock: 5);
        $this->actingAs($this->admin, 'admin');
    }

    private function window(): array
    {
        return ['starts_at' => StoreClock::now()->subHour()->format('Y-m-d\TH:i'), 'ends_at' => StoreClock::now()->addWeek()->format('Y-m-d\TH:i')];
    }

    public function test_every_marketing_page_renders(): void
    {
        $promotion = $this->buyXGetY($this->size, $this->gift);
        $spend = $this->spendXGetY([500 => $this->gift]);
        $coupon = $this->couponCode();
        $campaign = PromotionCampaign::create(['name_en' => 'Winter', 'is_active' => true]);
        $this->flashSaleOn($this->size);

        foreach (['/dashboard/marketing', '/dashboard/discounts', '/dashboard/discounts?section=buy_x_get_y&status=active&q=Buy', '/dashboard/discounts?status=archived',
                     "/dashboard/discounts/{$promotion->id}", "/dashboard/discounts/{$spend->id}", "/dashboard/discounts/{$promotion->id}/edit",
                     '/dashboard/campaigns', "/dashboard/coupons/{$coupon->id}", '/dashboard/flash_sales', '/dashboard/coupons?status=archived'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (DiscountRule::TYPES as $type) {
            $this->get('/dashboard/discounts/create?type='.$type)->assertOk();
        }
    }

    public function test_a_buy_x_get_y_promotion_is_created_with_its_reward_and_audited(): void
    {
        $this->post('/dashboard/discounts', $this->window() + [
            'discount_type' => 'buy_x_get_y', 'name_en' => 'Buy 2 get a gift', 'min_quantity' => 2, 'is_active' => 1, 'repeatable' => 1,
            'targets' => [['type' => 'product', 'id' => $this->size->product_id]],
            'rewards' => [['reward_type' => 'free_product', 'product_id' => $this->gift->product_id, 'product_size_id' => $this->gift->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $promotion = DiscountRule::sole();
        $this->assertTrue($promotion->is_active);
        $this->assertTrue($promotion->repeatable);
        $this->assertSame($this->gift->id, $promotion->rewards()->sole()->product_size_id);
        $this->assertSame($this->admin->id, $promotion->created_by);
        $this->assertTrue(AdminAuditLog::where('action', 'promotion.created')->where('subject_id', $promotion->id)->exists());
    }

    public function test_request_level_validation(): void
    {
        $this->from('/dashboard/discounts/create')->post('/dashboard/discounts', [
            'discount_type' => 'percent', 'discount_value' => 0, 'starts_at' => '2026-12-02', 'ends_at' => '2026-12-01',
        ])->assertSessionHasErrors(['discount_value', 'targets', 'ends_at']);

        $this->from('/dashboard/discounts/create')->post('/dashboard/discounts', $this->window() + [
            'discount_type' => 'amount_gift', 'rewards' => [['reward_type' => 'percent_discount', 'value' => 150, 'threshold_amount' => 100],
                ['reward_type' => 'free_product', 'product_id' => $this->size->product_id, 'product_size_id' => $this->gift->id, 'threshold_amount' => 100]],
        ])->assertSessionHasErrors(['rewards.0.value', 'rewards.1.product_size_id']);

        $this->from('/dashboard/discounts/create')->post('/dashboard/discounts', $this->window() + ['discount_type' => 'buy_x_get_y', 'min_quantity' => 2,
            'targets' => [['type' => 'product', 'id' => $this->size->product_id]]])->assertSessionHasErrors('rewards');
    }

    public function test_activation_is_blocked_while_the_configuration_has_critical_errors(): void
    {
        // Repeatable Spend X with two tiers is ambiguous: refused while active, allowed as a draft.
        $data = $this->window() + ['discount_type' => 'amount_gift', 'repeatable' => 1, 'is_active' => 1, 'rewards' => [
            ['reward_type' => 'free_product', 'product_id' => $this->gift->product_id, 'threshold_amount' => 500],
            ['reward_type' => 'fixed_discount', 'value' => 50, 'threshold_amount' => 1000],
        ]];
        $this->from('/dashboard/discounts/create')->post('/dashboard/discounts', $data)->assertSessionHasErrors('is_active');
        $this->assertSame(0, DiscountRule::count(), 'Nothing saved');

        $this->post('/dashboard/discounts', ['is_active' => 0] + $data)->assertSessionHasNoErrors();
        $draft = DiscountRule::sole();
        $this->from("/dashboard/discounts/{$draft->id}")->post("/dashboard/discounts/{$draft->id}/toggle")->assertSessionHasErrors('is_active');
        $this->assertFalse($draft->fresh()->is_active);
        $this->get("/dashboard/discounts/{$draft->id}")->assertSee('A repeatable Spend X promotion can only have one tier.');
    }

    public function test_two_active_discounts_on_the_same_target_with_the_same_priority_are_refused(): void
    {
        $this->promotion('percent', ['discount_value' => 10], [['product', $this->size->product_id]]);

        $data = $this->window() + ['discount_type' => 'percent', 'discount_value' => 20, 'is_active' => 1, 'targets' => [['type' => 'product', 'id' => $this->size->product_id]]];
        $this->from('/dashboard/discounts/create')->post('/dashboard/discounts', $data)->assertSessionHasErrors('is_active');

        $this->post('/dashboard/discounts', ['priority' => 5] + $data)->assertSessionHasNoErrors();
        $this->assertSame(2, DiscountRule::where('is_active', true)->count());
    }

    public function test_duplicate_deactivate_and_archive(): void
    {
        $promotion = $this->buyXGetY($this->size, $this->gift);

        $this->post("/dashboard/discounts/{$promotion->id}/duplicate")->assertRedirect();
        $copy = DiscountRule::where('id', '!=', $promotion->id)->sole();
        $this->assertFalse($copy->is_active);
        $this->assertSame('Buy x get y (copy)', $copy->name_en);
        $this->assertSame(1, $copy->rewards()->count());
        $this->assertSame(1, $copy->targets()->count());

        $this->post("/dashboard/discounts/{$promotion->id}/toggle")->assertSessionHasNoErrors();
        $this->assertFalse($promotion->fresh()->is_active);

        $this->delete("/dashboard/discounts/{$promotion->id}")->assertRedirect();
        $this->assertSoftDeleted('discount_rules', ['id' => $promotion->id]);
        $this->assertSame(['promotion.duplicated', 'promotion.deactivated', 'promotion.archived'], AdminAuditLog::orderBy('id')->pluck('action')->all());
        $this->get("/dashboard/discounts/{$promotion->id}")->assertOk()->assertSee('Archived');
    }

    public function test_the_preview_runs_the_engine_on_a_sample_cart_for_a_draft(): void
    {
        $promotion = $this->buyXGetY($this->size, $this->gift, attributes: ['is_active' => false, 'starts_at' => StoreClock::now()->addWeek(), 'ends_at' => StoreClock::now()->addWeeks(2)]);

        $this->post("/dashboard/discounts/{$promotion->id}/simulate", ['lines' => [['product_size_id' => $this->size->id, 'quantity' => 2]]])
            ->assertOk()
            ->assertSee('Total savings')
            ->assertViewHas('simulation', fn ($result) => count($result->gifts) === 1 && $result->gifts[0]['size']->id === $this->gift->id && $result->total() === 20000);

        $this->post("/dashboard/discounts/{$promotion->id}/simulate", ['lines' => [['product_size_id' => $this->size->id, 'quantity' => 1]]])
            ->assertOk()->assertSee('buy_quantity_not_reached');

        // Nothing was written.
        $this->assertSame(0, \App\Models\Cart::count());
        $this->assertSame(0, \App\Models\Order::count());
    }

    public function test_flash_sales_need_an_end_and_cannot_overlap_on_a_product(): void
    {
        $date = StoreClock::now()->addDay()->toDateString();
        $this->from('/dashboard/flash_sales')->post('/dashboard/flash_sales', ['discount_value' => 10, 'is_active' => 1, 'date' => $date, 'time' => '10:00'])
            ->assertSessionHasErrors('ends_at');

        $this->post('/dashboard/flash_sales', ['discount_value' => 10, 'is_active' => 1, 'date' => $date, 'time' => '10:00', 'ends_at' => $date.' 14:00',
            'product_ids' => [$this->size->product_id], 'sync_items' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, FlashSale::sole()->items()->count());

        $this->from('/dashboard/flash_sales')->post('/dashboard/flash_sales', ['discount_value' => 30, 'discount_type' => 'fixed', 'is_active' => 1, 'date' => $date, 'time' => '12:00', 'ends_at' => $date.' 16:00',
            'size_ids' => [$this->size->id], 'sync_items' => 1])->assertSessionHasErrors('product_ids');

        $this->post('/dashboard/flash_sales', ['discount_value' => 30, 'discount_type' => 'fixed', 'is_active' => 1, 'date' => $date, 'time' => '14:00', 'ends_at' => $date.' 16:00',
            'size_ids' => [$this->size->id], 'sync_items' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, FlashSale::count());
    }

    public function test_editing_a_coupon_never_resets_its_usage(): void
    {
        $coupon = $this->couponCode(['used_count' => 7, 'usage_limit' => 10]);

        $this->put("/dashboard/coupons/{$coupon->id}", ['code' => $coupon->code, 'discount_value' => 15, 'start_at' => now()->toDateString(), 'end_at' => now()->addMonth()->toDateString(),
            'is_active' => 1, 'customer_scope' => 'specific', 'customer_emails' => User::factory()->create(['email' => 'vip@example.com'])->email,
            'target_categories' => [], 'first_order_only' => 1, 'combines_with_promotions' => 0])->assertSessionHasNoErrors()->assertRedirect();

        $coupon->refresh();
        $this->assertSame(7, $coupon->used_count);
        $this->assertTrue($coupon->first_order_only);
        $this->assertSame(['vip@example.com'], $coupon->customers->pluck('email')->all());
        $this->assertTrue(AdminAuditLog::where('action', 'coupon.updated')->exists());

        $this->from('/dashboard/coupons')->post('/dashboard/coupons', ['code' => 'ZERO', 'discount_value' => 0, 'start_at' => now()->toDateString(), 'end_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('discount_value');
    }

    public function test_marketing_needs_the_marketing_permission(): void
    {
        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();

        foreach (['/dashboard/marketing', '/dashboard/discounts', '/dashboard/campaigns', '/dashboard/coupons'] as $url) {
            $this->actingAs($agent, 'admin')->get($url)->assertForbidden();
        }
    }

    public function test_the_customer_promotions_api_lists_what_is_running(): void
    {
        $this->buyXGetY($this->size, $this->gift);
        $this->promotion('percent', ['discount_value' => 10, 'customer_scope' => 'specific'], [['product', $this->size->product_id]]);
        $this->promotion('fixed', ['discount_value' => 5, 'is_active' => false], [['product', $this->size->product_id]]);
        $this->flashSaleOn($this->gift);

        $data = $this->getJson('/api/v1/user/promotions')->assertOk()->json('data');
        $this->assertSame(['buy_x_get_y'], array_column($data['promotions'], 'type'));
        $this->assertSame($this->gift->product_id, $data['promotions'][0]['rewards'][0]['product_id']);
        $this->assertCount(1, $data['flash_sales']);
    }
}
