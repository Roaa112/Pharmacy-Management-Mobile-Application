<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromotion;
use App\Models\ProductSize;
use App\Models\PromotionRedemption;
use App\Models\StockReservation;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Buy X get Y: gifts are added, resized and removed by the backend as the cart changes, and
 * the order gets real, reserved gift lines.
 */
class BuyXGetYTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;
    private ProductSize $bought;
    private ProductSize $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->bought = $this->makeSize(price: 50, stock: 20);
        $this->gift = $this->makeSize(price: 30, stock: 10);
    }

    public function test_the_gift_is_added_automatically_as_a_separate_zero_priced_line(): void
    {
        $promotion = $this->buyXGetY($this->bought, $this->gift);

        $this->add($this->bought, 1);
        $this->assertSame([], $this->giftLines(), 'One unit does not qualify yet');

        $response = $this->add($this->bought, 1);
        $response->assertJsonPath('gift_changes.0.type', 'added')->assertJsonPath('gift_changes.0.promotion_id', $promotion->id);

        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        $cart = $this->cart();
        $gift = collect($cart->json('data'))->firstWhere('is_gift', true);
        $this->assertSame($this->gift->id, $gift['product_size_id']);
        $this->assertEquals(0, $gift['discounted_price']);
        $this->assertEquals(0, $gift['line_total']);
        $this->assertEquals(30, $gift['original_price']);
        $this->assertFalse($gift['editable']);
        $this->assertSame($promotion->id, $gift['gift']['promotion_id']);
        $this->assertSame('Buy x get y', $gift['gift']['promotion_name']);
        // The gift is not charged: the cart total is the paid items only.
        $this->assertEquals(100, $cart->json('total_price'));
        $this->assertEquals(30, $cart->json('pricing.gift_value'));
    }

    public function test_the_gift_quantity_follows_the_qualifying_quantity_when_repeatable(): void
    {
        $this->buyXGetY($this->bought, $this->gift, attributes: ['repeatable' => true]);

        $this->add($this->bought, 4);
        $this->assertSame([$this->gift->id => 2], $this->giftLines());

        $this->setQuantity($this->bought, 3);
        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        $this->setQuantity($this->bought, 2);
        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        $this->setQuantity($this->bought, 1);
        $this->assertSame([], $this->giftLines(), 'Below X the gift disappears');
    }

    public function test_a_non_repeatable_promotion_gives_the_gift_once_and_max_applications_caps_repeats(): void
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 6);
        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        Cart::query()->delete();
        $other = $this->makeSize(price: 50, stock: 20);
        $this->buyXGetY($other, $this->gift, attributes: ['repeatable' => true, 'max_applications_per_order' => 2]);
        $this->add($other, 10);
        $this->assertSame([$this->gift->id => 2], $this->giftLines());
    }

    public function test_removing_the_qualifying_item_removes_the_gift_with_a_reason(): void
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);
        $line = Cart::customerLines()->firstOrFail();

        $this->deleteJson("/api/user/cart/{$line->id}")->assertOk()
            ->assertJsonPath('gift_changes.0.type', 'removed');

        $this->assertSame([], $this->giftLines());
        $this->assertSame(0, Cart::count());
    }

    public function test_decreasing_below_x_reports_why_the_gift_went(): void
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);

        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $this->bought->product_id, 'old_size_id' => $this->bought->id, 'operation' => 'decrement'])
            ->assertOk()
            ->assertJsonPath('gift_changes.0.type', 'removed')
            ->assertJsonPath('gift_changes.0.reason', 'buy_quantity_not_reached');
    }

    public function test_changing_the_variant_re_evaluates_the_gift(): void
    {
        // Only the "M" variant qualifies.
        $other = $this->bought->product->sizes()->create(['size' => 'L', 'price' => 50, 'stock' => 10]);
        $this->promotion('buy_x_get_y', ['min_quantity' => 2], [['variant', $this->bought->id]],
            [['reward_type' => 'free_product', 'product_id' => $this->gift->product_id, 'product_size_id' => $this->gift->id]]);

        $this->add($this->bought, 2);
        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $this->bought->product_id, 'old_size_id' => $this->bought->id, 'new_size_id' => $other->id])->assertOk();
        $this->assertSame([], $this->giftLines());
    }

    public function test_a_same_product_reward_is_an_extra_line_and_respects_stock(): void
    {
        $size = $this->makeSize(price: 40, stock: 3);
        $this->buyXGetY($size, $size);

        $this->add($size, 2);
        $this->assertSame(2, (int) Cart::customerLines()->value('product_quantity'));
        $this->assertSame([$size->id => 1], $this->giftLines());

        // 3 paid units leave no unit in stock for the gift.
        $this->add($size, 1);
        $this->assertSame([], $this->giftLines());
        $this->cart()->assertJsonPath('pricing.rejected_promotions.0.reason', 'gift_out_of_stock');
    }

    public function test_an_out_of_stock_gift_is_not_added_and_the_reason_is_given(): void
    {
        ProductSize::whereKey($this->gift->id)->update(['stock' => 0]);
        $this->buyXGetY($this->bought, $this->gift);

        $this->add($this->bought, 2);

        $this->assertSame([], $this->giftLines());
        $reasons = collect($this->cart()->json('pricing.rejected_promotions'))->pluck('reason');
        $this->assertContains('gift_out_of_stock', $reasons);
    }

    public function test_partial_stock_gives_what_is_left_and_skip_gives_nothing(): void
    {
        ProductSize::whereKey($this->gift->id)->update(['stock' => 1]);
        $this->buyXGetY($this->bought, $this->gift, quantity: 3);
        $this->add($this->bought, 2);
        $this->assertSame([$this->gift->id => 1], $this->giftLines(), 'partial (default) gives the one unit left');

        Cart::query()->delete();
        $other = $this->makeSize(price: 50, stock: 20);
        $this->buyXGetY($other, $this->gift, quantity: 3, attributes: ['gift_fallback' => 'skip']);
        $this->add($other, 2);
        $this->assertSame([], $this->giftLines());
    }

    public function test_a_block_fallback_refuses_checkout_until_the_gift_is_back(): void
    {
        ProductSize::whereKey($this->gift->id)->update(['stock' => 0]);
        $this->buyXGetY($this->bought, $this->gift, attributes: ['gift_fallback' => 'block']);
        $this->add($this->bought, 2);

        $this->checkout()->assertStatus(422)->assertJsonPath('code', 'gift_unavailable');
        $this->assertSame(0, Order::count());
    }

    public function test_repeated_recalculation_never_duplicates_a_gift(): void
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);

        for ($i = 0; $i < 5; $i++) {
            $this->cart();
        }

        $this->assertSame(1, Cart::giftLines()->count());
        $this->assertSame([$this->gift->id => 1], $this->giftLines());
    }

    public function test_the_customer_cannot_edit_or_remove_a_gift_line(): void
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);
        $gift = Cart::giftLines()->firstOrFail();

        $this->deleteJson("/api/user/cart/{$gift->id}")->assertStatus(422)->assertJsonPath('code', 'gift_not_editable');
        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $this->gift->product_id, 'operation' => 'increment'])
            ->assertStatus(422)->assertJsonPath('code', 'gift_not_editable');

        // Adding the gift product itself creates a separate paid line; the gift stays a gift.
        $this->add($this->gift, 1);
        $this->assertSame(1, Cart::giftLines()->count());
        $this->assertSame(1, (int) Cart::customerLines()->where('product_size_id', $this->gift->id)->value('product_quantity'));
    }

    public function test_checkout_creates_a_reserved_gift_line_with_snapshots_and_cancel_releases_it(): void
    {
        $promotion = $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);

        $id = $this->checkout()->assertCreated()
            ->assertJsonPath('total_price', 100)
            ->assertJsonPath('gifts.0.product_size_id', $this->gift->id)
            ->assertJsonPath('total_savings', 30)
            ->json('order_id');

        $line = OrderItem::where('order_id', $id)->where('is_gift', true)->sole();
        $this->assertEquals(0, $line->price_at_time);
        $this->assertEquals(30, $line->original_price);
        $this->assertEquals(30, $line->discount_amount);
        $this->assertSame($promotion->id, $line->discount_rule_id);
        $this->assertSame($promotion->rewards->first()->id, $line->promotion_reward_id);
        $this->assertSame('granted', $line->gift_status);
        $this->assertSame('Buy x get y', $line->promotion_snapshot['promotion_name']);
        $this->assertTrue(StockReservation::where('order_item_id', $line->id)->where('status', 'active')->exists());
        $this->assertSame(9, $this->gift->fresh()->stock);

        $snapshot = OrderPromotion::where('order_id', $id)->where('source', 'promotion')->sole();
        $this->assertSame(1, $snapshot->reward_quantity);
        $this->assertSame($line->id, $snapshot->details['gifts'][0]['order_item_id']);
        $this->assertSame(PromotionRedemption::RESERVED, PromotionRedemption::where('order_id', $id)->value('status'));
        $this->assertSame(0, Cart::count(), 'The cart (gift lines included) is emptied');

        app(OrderWorkflow::class)->transition(Order::find($id), OrderStatus::Canceled, OrderWorkflow::ACTOR_SYSTEM);
        $this->assertSame(10, $this->gift->fresh()->stock);
        $this->assertSame(PromotionRedemption::RELEASED, PromotionRedemption::where('order_id', $id)->value('status'));
    }

    public function test_checkout_re_decides_gifts_and_ignores_stale_or_tampered_gift_lines(): void
    {
        $promotion = $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);
        // Tampering: 5 gift units, and a gift line for a product that is no reward at all.
        Cart::giftLines()->update(['product_quantity' => 5]);
        Cart::create(['user_id' => $this->user->id, 'product_id' => $this->bought->product_id, 'product_size_id' => $this->bought->id, 'product_quantity' => 3,
            'price_at_time' => 0, 'total_price' => 0, 'original_price' => 50, 'is_gift' => true, 'gift_key' => 'fake']);

        $id = $this->checkout()->assertCreated()->json('order_id');

        $gifts = OrderItem::where('order_id', $id)->where('is_gift', true)->get();
        $this->assertCount(1, $gifts);
        $this->assertSame(1, $gifts->first()->quantity);
        $this->assertSame(2, (int) OrderItem::where('order_id', $id)->where('is_gift', false)->sum('quantity'));
    }

    public function test_a_promotion_that_expires_before_checkout_gives_no_gift(): void
    {
        $promotion = $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);
        $this->assertSame([$this->gift->id => 1], $this->giftLines());

        $promotion->update(['ends_at' => StoreClock::now()->subMinute()]);

        $id = $this->checkout()->assertCreated()->json('order_id');
        $this->assertFalse(OrderItem::where('order_id', $id)->where('is_gift', true)->exists());

        // And the cart view drops it with the reason.
        $this->add($this->bought, 2);
        $this->assertSame([], $this->giftLines());
    }

    public function test_items_already_on_promotion_do_not_count_unless_the_offer_is_stackable(): void
    {
        $this->flashSaleOn($this->bought, 10);
        $promotion = $this->buyXGetY($this->bought, $this->gift);

        $this->add($this->bought, 2);
        $this->assertSame([], $this->giftLines());
        $this->assertContains('qualifying_items_discounted', collect($this->cart()->json('pricing.rejected_promotions'))->pluck('reason'));

        $promotion->update(['is_stackable' => true]);
        $this->cart();
        $this->assertSame([$this->gift->id => 1], $this->giftLines());
    }
}
