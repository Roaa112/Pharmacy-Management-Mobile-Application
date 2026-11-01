<?php

namespace Tests\Feature\Marketing;

use App\Models\Brand;
use App\Models\Category;
use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\PromotionCampaign;
use App\Models\User;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** One price-affecting promotion per line unless explicitly stackable; deterministic winners. */
class PromotionConflictsTest extends TestCase
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

    private function linePrice(): float
    {
        \App\Models\Cart::query()->delete();
        $this->add($this->size, 1);

        return (float) $this->cart()->json('data.0.discounted_price');
    }

    public function test_a_flash_sale_beats_a_percentage_and_a_fixed_discount_without_stacking(): void
    {
        $this->flashSaleOn($this->size, 10);
        $percent = $this->promotion('percent', ['discount_value' => 30, 'priority' => 100], [['product', $this->size->product_id]]);
        $fixed = $this->promotion('fixed', ['discount_value' => 40], [['product', $this->size->product_id]]);

        $this->assertEquals(90, $this->linePrice());
        $rejected = collect($this->cart()->json('pricing.rejected_promotions'))->pluck('reason', 'id');
        $this->assertSame('conflict_lower_precedence', $rejected[$percent->id]);
        $this->assertSame('conflict_lower_precedence', $rejected[$fixed->id]);
        $this->assertEquals(90, Product::find($this->size->product_id)->priceForSize($this->size), 'Listing agrees');
    }

    public function test_priority_then_specificity_then_age_decide_never_the_query_order(): void
    {
        $category = Category::create(['name_ar' => 'ف', 'name_en' => 'C', 'image' => 'c.png']);
        $brand = Brand::create(['name_ar' => 'ب', 'name_en' => 'B', 'image' => 'b.png']);
        $this->size->product->update(['category_id' => $category->id, 'brand_id' => $brand->id]);

        $this->promotion('percent', ['discount_value' => 50], [['category', $category->id]]);
        $this->promotion('percent', ['discount_value' => 20], [['brand', $brand->id]]);
        $variant = $this->promotion('percent', ['discount_value' => 5], [['variant', $this->size->id]]);
        $this->assertEquals(95, $this->linePrice(), 'Most specific (variant) wins at equal priority');

        $this->promotion('percent', ['discount_value' => 50, 'priority' => 1], [['category', $category->id]]);
        $this->assertEquals(50, $this->linePrice(), 'Higher priority wins over specificity');

        // Two equal ones: the older wins.
        DiscountRule::query()->delete();
        $older = $this->promotion('fixed', ['discount_value' => 10], [['product', $this->size->product_id]]);
        $this->promotion('fixed', ['discount_value' => 30], [['product', $this->size->product_id]]);
        $this->assertEquals(90, $this->linePrice());
    }

    public function test_explicitly_stackable_discounts_apply_in_order_and_never_below_zero(): void
    {
        $this->promotion('percent', ['discount_value' => 10, 'is_stackable' => true, 'priority' => 2], [['product', $this->size->product_id]]);
        $this->promotion('fixed', ['discount_value' => 5, 'is_stackable' => true, 'priority' => 1], [['product', $this->size->product_id]]);
        $this->assertEquals(85, $this->linePrice(), '100 −10% = 90, −5 = 85');

        $this->promotion('fixed', ['discount_value' => 500, 'is_stackable' => true], [['product', $this->size->product_id]]);
        $this->assertEquals(0, $this->linePrice());
    }

    public function test_an_exclusive_or_non_stackable_winner_blocks_stacking(): void
    {
        $this->promotion('percent', ['discount_value' => 10, 'is_stackable' => true, 'is_exclusive' => true, 'priority' => 2], [['product', $this->size->product_id]]);
        $this->promotion('fixed', ['discount_value' => 5, 'is_stackable' => true], [['product', $this->size->product_id]]);
        $this->assertEquals(90, $this->linePrice());
    }

    public function test_a_coupon_does_not_apply_to_flash_sale_or_discounted_items_unless_it_combines(): void
    {
        $this->flashSaleOn($this->size, 10);
        $plain = $this->couponCode();
        $combining = $this->couponCode(['combines_with_promotions' => true]);
        $this->add($this->size, 1);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $plain->code])->assertStatus(400)->assertJsonPath('code', 'no_eligible_items');
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $combining->code])->assertOk()->assertJsonPath('discount', 9);

        // A mixed cart: the plain coupon discounts only the undiscounted item.
        $other = $this->makeSize(price: 50, stock: 5);
        $this->add($other, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $plain->code])->assertOk()->assertJsonPath('discount', 5);
    }

    public function test_a_coupon_and_an_automatic_order_discount_do_not_stack_the_larger_applies(): void
    {
        $this->promotion('amount_gift', [], [], [['reward_type' => 'fixed_discount', 'threshold_amount' => 50, 'value' => 15]]);
        $small = $this->couponCode(['discount_value' => 10]);   // 10
        $big = $this->couponCode(['discount_value' => 20]);     // 20
        $this->add($this->size, 1);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $small->code])->assertStatus(400)->assertJsonPath('code', 'conflict_better_offer');
        $this->cart()->assertJsonPath('pricing.promotion_discount', 15);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $big->code])->assertOk()
            ->assertJsonPath('discount', 20)
            ->assertJsonPath('pricing.promotion_discount', 0)
            ->assertJsonPath('total_after_discount', 80);

        // A combining coupon applies after the automatic discount: 10% of (100 − 15).
        $both = $this->couponCode(['discount_value' => 10, 'combines_with_promotions' => true]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $both->code])->assertOk()
            ->assertJsonPath('pricing.promotion_discount', 15)
            ->assertJsonPath('discount', 8.5)
            ->assertJsonPath('total_after_discount', 76.5);
    }

    public function test_buy_x_get_y_and_an_item_discount_on_the_same_units_do_not_combine(): void
    {
        $gift = $this->makeSize(price: 20, stock: 5);
        $this->promotion('percent', ['discount_value' => 10], [['product', $this->size->product_id]]);
        $this->buyXGetY($this->size, $gift);

        $this->add($this->size, 2);
        $this->assertEquals(90, (float) $this->cart()->json('data.0.discounted_price'));
        $this->assertSame([], $this->giftLines());
    }

    public function test_two_campaigns_giving_the_same_reward_give_it_once(): void
    {
        $gift = $this->makeSize(price: 20, stock: 5);
        $first = PromotionCampaign::create(['name_en' => 'A', 'is_active' => true]);
        $second = PromotionCampaign::create(['name_en' => 'B', 'is_active' => true]);
        $winner = $this->buyXGetY($this->size, $gift, attributes: ['campaign_id' => $first->id, 'priority' => 5]);
        $loser = $this->buyXGetY($this->size, $gift, attributes: ['campaign_id' => $second->id]);

        $this->add($this->size, 2);
        $this->assertSame([$gift->id => 1], $this->giftLines());
        $this->assertSame($winner->id, (int) \App\Models\Cart::giftLines()->value('discount_rule_id'));
        $this->assertSame('duplicate_reward', collect($this->cart()->json('pricing.rejected_promotions'))->firstWhere('id', $loser->id)['reason']);
    }

    public function test_an_exclusive_order_promotion_blocks_the_others(): void
    {
        $giftA = $this->makeSize(price: 20, stock: 5);
        $giftB = $this->makeSize(price: 25, stock: 5);
        $this->buyXGetY($this->size, $giftA, attributes: ['is_exclusive' => true, 'priority' => 3]);
        $blocked = $this->spendXGetY([50 => $giftB]);

        $this->add($this->size, 2);
        $this->assertSame([$giftA->id => 1], $this->giftLines());
        $this->assertSame('blocked_by_exclusive', collect($this->cart()->json('pricing.rejected_promotions'))->firstWhere('id', $blocked->id)['reason']);
    }

    public function test_an_inactive_campaign_or_another_country_disables_its_promotions(): void
    {
        $campaign = PromotionCampaign::create(['name_en' => 'Paused', 'is_active' => false]);
        $this->promotion('percent', ['discount_value' => 10, 'campaign_id' => $campaign->id], [['product', $this->size->product_id]]);
        $this->assertEquals(100, $this->linePrice());

        $campaign->update(['is_active' => true]);
        $this->assertEquals(90, $this->linePrice());

        $abroad = \App\Models\Country::create(['iso2' => 'IQ', 'name_en' => 'Iraq', 'name_ar' => 'العراق', 'currency_code' => 'IQD', 'timezone' => 'Asia/Baghdad', 'phone_code' => '+964']);
        DiscountRule::query()->update(['country_id' => $abroad->id]);
        Product::flushActiveDiscountCache(); // a mass update fires no model event
        $this->assertEquals(100, $this->linePrice());
    }

    public function test_customer_scope_and_per_customer_limits(): void
    {
        $vip = $this->promotion('percent', ['discount_value' => 10, 'customer_scope' => 'specific'], [['product', $this->size->product_id]]);
        $this->assertEquals(100, $this->linePrice());

        $vip->customers()->attach($this->user->id);
        Product::flushActiveDiscountCache(); // a pivot change fires no model event (the admin service flushes)
        $this->assertEquals(90, $this->linePrice());

        $vip->update(['per_customer_limit' => 1]);
        $this->checkout()->assertCreated();
        $this->assertEquals(100, $this->linePrice(), 'Used once: the limit is reached');
    }

    public function test_new_customer_promotions_only_until_the_first_order(): void
    {
        $this->promotion('percent', ['discount_value' => 10, 'customer_scope' => 'new'], [['product', $this->size->product_id]]);
        $this->assertEquals(90, $this->linePrice());
        $this->checkout()->assertCreated()->assertJsonPath('total_price', 90);
        $this->assertEquals(100, $this->linePrice());
    }

    public function test_minimum_quantity_and_order_bounds_apply_in_the_cart_not_in_the_listing(): void
    {
        $this->promotion('percent', ['discount_value' => 10, 'min_quantity' => 3], [['product', $this->size->product_id]]);

        $this->assertEquals(100, Product::find($this->size->product_id)->priceForSize($this->size));
        $this->add($this->size, 3);
        $this->assertEquals(90, (float) $this->cart()->json('data.0.discounted_price'));
    }

    public function test_flash_sale_quantity_and_customer_limits(): void
    {
        $sale = $this->flashSaleOn($this->size, 50, ['quantity_limit' => 3, 'per_customer_limit' => 2]);

        $this->add($this->size, 3);
        $this->cart()->assertJsonPath('data.0.discounted_price', 100)
            ->assertJsonPath('pricing.rejected_promotions.0.reason', 'flash_customer_limit');

        $this->setQuantity($this->size, 2);
        $this->cart()->assertJsonPath('data.0.discounted_price', 50);
        $this->checkout()->assertCreated()->assertJsonPath('total_price', 100);

        // The customer used their 2; another customer gets the last unit at the flash price.
        $this->add($this->size, 1);
        $this->cart()->assertJsonPath('data.0.discounted_price', 100);
        Sanctum::actingAs(User::factory()->create());
        $this->add($this->size, 1);
        $this->cart()->assertJsonPath('data.0.discounted_price', 50);
        $this->checkout()->assertCreated();
        $this->assertEquals(100, Product::find($this->size->product_id)->priceForSize($this->size), 'Sold out at the flash price');
    }

    public function test_on_sale_listings_show_only_products_the_engine_discounts(): void
    {
        $listed = fn () => collect($this->getJson('/api/v1/user/products/on/sale')->assertOk()->json('data.top_percent_discount.products'))->pluck('id')->all();
        $excluded = $this->makeSize(price: 100, stock: 5);
        $rule = $this->promotion('percent', ['discount_value' => 10], [['product', $this->size->product_id], ['product', $excluded->product_id], ['product', $excluded->product_id, true]]);

        $this->assertSame([$this->size->product_id], $listed(), 'The excluded product is not on sale');

        $rule->update(['is_active' => false]);
        $this->assertSame([], $listed());
    }

    public function test_the_same_promotion_is_never_applied_twice(): void
    {
        $promotion = $this->promotion('percent', ['discount_value' => 10, 'is_stackable' => true], [['product', $this->size->product_id], ['variant', $this->size->id]]);
        $this->assertEquals(90, $this->linePrice());
        $applied = collect($this->cart()->json('pricing.applied_promotions'))->where('id', $promotion->id);
        $this->assertCount(1, $applied);
    }
}
