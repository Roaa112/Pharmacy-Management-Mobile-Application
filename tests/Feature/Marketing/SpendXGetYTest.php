<?php

namespace Tests\Feature\Marketing;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\User;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Spend X get Y: the gift follows the qualifying spend exactly (threshold reached → added,
 * lost → removed), through tiers, coupons, exclusions, price changes and expiry.
 */
class SpendXGetYTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;
    private ProductSize $item;
    private ProductSize $small;
    private ProductSize $giftA;
    private ProductSize $giftB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->item = $this->makeSize(price: 2600, stock: 20);
        $this->small = $this->makeSize(price: 300, stock: 20);
        $this->giftA = $this->makeSize(price: 100, stock: 10);
        $this->giftB = $this->makeSize(price: 200, stock: 10);
    }

    public function test_the_gift_is_added_at_the_threshold_and_removed_when_the_spend_drops(): void
    {
        $this->spendXGetY([5000 => $this->giftA]);

        $this->add($this->item, 1);
        $this->assertSame([], $this->giftLines());
        $this->cart()->assertJsonPath('pricing.next_rewards.0.missing_amount', 2400);

        // 2 × 2600 = 5200 ≥ 5000.
        $this->add($this->item, 1);
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());

        // 5200 + 300 = 5500, then remove the 300 item → 5200: still qualifies.
        $this->add($this->small, 1);
        $this->deleteJson('/api/user/cart/'.Cart::customerLines()->where('product_size_id', $this->small->id)->value('id'))->assertOk();
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());

        // Down to one unit: 2600 < 5000 → the gift goes, with the reason.
        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $this->item->product_id, 'old_size_id' => $this->item->id, 'operation' => 'decrement'])
            ->assertOk()
            ->assertJsonPath('gift_changes.0.type', 'removed')
            ->assertJsonPath('gift_changes.0.reason', 'minimum_spend_not_reached');
        $this->assertSame([], $this->giftLines());
    }

    public function test_5200_minus_a_300_item_is_4900_and_loses_the_gift(): void
    {
        $this->spendXGetY([5000 => $this->giftA]);
        $base = $this->makeSize(price: 4900, stock: 5);

        $this->add($base, 1);
        $this->add($this->small, 1); // 5200
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());

        $this->deleteJson('/api/user/cart/'.Cart::customerLines()->where('product_size_id', $this->small->id)->value('id'))
            ->assertOk()->assertJsonPath('gift_changes.0.reason', 'minimum_spend_not_reached');
        $this->assertSame([], $this->giftLines());
    }

    public function test_cumulative_tiers_downgrade_keeps_the_lower_gift(): void
    {
        $this->spendXGetY([5000 => $this->giftA, 10000 => $this->giftB], ['tier_mode' => 'cumulative']);

        $this->add($this->item, 4); // 10,400
        $this->assertSame([$this->giftA->id => 1, $this->giftB->id => 1], $this->giftLines());

        $this->setQuantity($this->item, 3); // 7,800
        $this->assertSame([$this->giftA->id => 1], $this->giftLines(), 'Gift B removed, gift A kept');

        $this->setQuantity($this->item, 1); // 2,600
        $this->assertSame([], $this->giftLines());
    }

    public function test_highest_tier_mode_gives_only_the_highest_reward_reached(): void
    {
        $this->spendXGetY([5000 => $this->giftA, 10000 => $this->giftB]);

        $this->add($this->item, 4);
        $this->assertSame([$this->giftB->id => 1], $this->giftLines());

        $response = $this->postJson('/api/user/cart/update-quantity', ['product_id' => $this->item->product_id, 'old_size_id' => $this->item->id, 'operation' => 'decrement'])->assertOk();
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());
        $removed = collect($response->json('gift_changes'))->firstWhere('type', 'removed');
        $this->assertSame('minimum_spend_not_reached', $removed['reason']);
    }

    public function test_gift_items_never_count_toward_their_own_threshold(): void
    {
        // The gift is worth 1000 and qualifies as a target, but gift lines are never inputs.
        $expensiveGift = $this->makeSize(price: 1000, stock: 5);
        $this->spendXGetY([5000 => $expensiveGift]);
        $base = $this->makeSize(price: 4500, stock: 5);

        $this->add($base, 1);
        $this->assertSame([], $this->giftLines());
        $this->cart()->assertJsonPath('pricing.subtotal', 4500);
    }

    public function test_excluded_products_and_targets_define_the_qualifying_spend(): void
    {
        $category = Category::create(['name_ar' => 'ف', 'name_en' => 'Devices', 'image' => 'c.png']);
        $this->item->product->update(['category_id' => $category->id]);
        $this->small->product->update(['category_id' => $category->id]);

        $this->spendXGetY([5300 => $this->giftA], [], [['category', $category->id], ['product', $this->small->product_id, true]]);

        $this->add($this->item, 2);  // 5200 eligible
        $this->add($this->small, 1); // excluded: still 5200
        $this->assertSame([], $this->giftLines());
        $this->assertSame(5200.0, (float) $this->cart()->json('pricing.rejected_promotions.0.details.qualifying_amount'));
    }

    public function test_a_coupon_that_lowers_the_qualifying_spend_removes_the_gift(): void
    {
        $this->spendXGetY([5000 => $this->giftA]);
        $coupon = $this->couponCode(['discount_value' => 10]);
        $this->add($this->item, 2); // 5200
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());

        // 10% coupon: 5200 − 520 = 4680 < 5000.
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk()
            ->assertJsonPath('gift_changes.0.type', 'removed');
        $this->assertSame([], $this->giftLines());

        // Checkout with the coupon agrees: no gift line.
        $id = $this->checkout(['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('total_price', 4680)->json('order_id');
        $this->assertFalse(OrderItem::where('order_id', $id)->where('is_gift', true)->exists());
    }

    public function test_when_configured_the_coupon_does_not_reduce_the_qualifying_spend(): void
    {
        $this->spendXGetY([5000 => $this->giftA], ['coupon_reduces_qualifying' => false]);
        $coupon = $this->couponCode(['discount_value' => 10]);
        $this->add($this->item, 2);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertOk();
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());
    }

    public function test_a_price_change_or_an_ineligible_item_re_evaluates_the_gift(): void
    {
        $this->spendXGetY([5000 => $this->giftA]);
        $this->add($this->item, 2);
        $this->assertSame([$this->giftA->id => 1], $this->giftLines());

        ProductSize::whereKey($this->item->id)->update(['price' => 2400]); // 4800 now
        $this->cart();
        $this->assertSame([], $this->giftLines());
    }

    public function test_an_expired_promotion_removes_the_gift(): void
    {
        $promotion = $this->spendXGetY([5000 => $this->giftA]);
        $this->add($this->item, 2);

        $promotion->update(['ends_at' => StoreClock::now()->subSecond()]);
        $this->cart()->assertJsonPath('gift_changes.0.reason', 'promotion_ended');
        $this->assertSame([], $this->giftLines());
    }

    public function test_an_out_of_stock_gift_is_not_given(): void
    {
        ProductSize::whereKey($this->giftA->id)->update(['stock' => 0]);
        $this->spendXGetY([5000 => $this->giftA]);
        $this->add($this->item, 2);

        $this->assertSame([], $this->giftLines());
        $id = $this->checkout()->assertCreated()->json('order_id');
        $this->assertFalse(OrderItem::where('order_id', $id)->where('is_gift', true)->exists());
    }

    public function test_fixed_discount_free_delivery_and_percentage_rewards(): void
    {
        $this->makeDeliveryPrice('Cairo', 50);
        $address = $this->makeAddress($this->user);
        $this->promotion('amount_gift', [], [], [
            ['reward_type' => 'fixed_discount', 'threshold_amount' => 5000, 'value' => 200],
            ['reward_type' => 'free_delivery', 'threshold_amount' => 5000],
        ]);
        $this->add($this->item, 2); // 5200

        $order = $this->checkout(['address_id' => $address->id])->assertCreated()
            ->assertJsonPath('delivery_fee', 50)
            ->assertJsonPath('promotion_discount', 200)
            ->assertJsonPath('delivery_discount', 50)
            ->assertJsonPath('total_price', 5000);
        $this->assertEquals(250, Order::find($order->json('order_id'))->discount_amount);

        Cart::query()->delete();
        $this->promotion('amount_gift', ['priority' => 5], [], [['reward_type' => 'percent_discount', 'threshold_amount' => 5000, 'value' => 10, 'max_discount_amount' => 300]]);
        $this->add($this->item, 2);
        // The higher-priority 10% (capped 300) wins over the 200 fixed; they do not stack.
        $this->cart()->assertJsonPath('pricing.promotion_discount', 300);
    }
}
