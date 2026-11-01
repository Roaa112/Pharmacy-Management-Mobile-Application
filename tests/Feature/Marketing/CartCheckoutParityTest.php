<?php

namespace Tests\Feature\Marketing;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromotion;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * The cart preview and checkout run the same engine on the same inputs: every amount the cart
 * shows is the amount the order charges, and the order keeps immutable snapshots.
 */
class CartCheckoutParityTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_preview_equals_checkout_with_every_kind_of_promotion_at_once(): void
    {
        $this->makeDeliveryPrice('Cairo', 40);
        $address = $this->makeAddress($this->user);
        $flash = $this->makeSize(price: 200, stock: 10);
        $discounted = $this->makeSize(price: 150, stock: 10);
        $plain = $this->makeSize(price: 99.99, stock: 10);
        $gift = $this->makeSize(price: 35, stock: 10);

        $this->flashSaleOn($flash, 25);
        $this->promotion('percent', ['discount_value' => 12.5], [['product', $discounted->product_id]]);
        $this->buyXGetY($plain, $gift);
        $this->promotion('free_delivery', ['min_amount' => 300]);
        $coupon = $this->couponCode(['discount_value' => 10, 'applies_to_delivery' => true]);

        $this->add($flash, 1);
        $this->add($discounted, 2);
        $this->add($plain, 2);

        // Recalculating many times changes nothing.
        foreach (range(1, 3) as $i) {
            $this->cart(['coupon' => $coupon->code, 'address_id' => $address->id]);
        }
        $preview = $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code, 'address_id' => $address->id])->assertOk();

        $order = $this->checkout(['address_id' => $address->id, 'coupon_code' => $coupon->code, 'expected_total' => $preview->json('total_after_discount')])->assertCreated();

        foreach (['subtotal' => 'subtotal', 'items_discount' => 'items_discount', 'coupon_discount' => 'coupon_discount', 'promotion_discount' => 'promotion_discount',
                     'delivery_fee' => 'delivery_fee', 'delivery_discount' => 'delivery_discount', 'total_savings' => 'total_savings', 'total_price' => 'total'] as $orderKey => $previewKey) {
            $this->assertEquals($preview->json("pricing.{$previewKey}"), $order->json($orderKey), "{$orderKey} differs from the preview");
        }
        $this->assertEquals($preview->json('total_after_discount'), $order->json('total_price'));

        // 150 (flash 200 −25%) + 262.50 (2 × 131.25) + 199.98 = 612.48; free delivery; coupon only on the plain line (19.998 → 20.00).
        $this->assertEquals(612.48, $order->json('subtotal'));
        $this->assertEquals(40, $order->json('delivery_discount'));
        $this->assertEquals(20, $order->json('coupon_discount'));
        $this->assertEquals(592.48, $order->json('total_price'));

        $orderId = $order->json('order_id');
        $this->assertSame(4, OrderItem::where('order_id', $orderId)->count());
        $this->assertSame(['coupon', 'flash_sale', 'promotion', 'promotion', 'promotion'], OrderPromotion::where('order_id', $orderId)->orderBy('source')->pluck('source')->all());
        $this->assertEquals(592.48, Order::find($orderId)->pricing_snapshot['total']);
    }

    public function test_a_stale_cart_price_is_never_charged_after_a_price_change(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $this->add($size, 2);
        Cart::query()->update(['price_at_time' => 1, 'total_price' => 2]);
        ProductSize::whereKey($size->id)->update(['price' => 120]);

        $this->cart()->assertJsonPath('total_price', 240);
        $this->checkout()->assertCreated()->assertJsonPath('total_price', 240);
    }

    public function test_concurrent_stock_changes_are_seen_at_checkout(): void
    {
        $size = $this->makeSize(price: 100, stock: 5);
        $gift = $this->makeSize(price: 10, stock: 1);
        $this->buyXGetY($size, $gift);
        $this->add($size, 2);
        $this->assertSame([$gift->id => 1], $this->giftLines());

        // The last gift unit is sold elsewhere before this customer checks out.
        ProductSize::moveStock($gift->id, -1, \App\Models\StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Sold at the counter']);

        $id = $this->checkout()->assertCreated()->json('order_id');
        $this->assertFalse(OrderItem::where('order_id', $id)->where('is_gift', true)->exists());
        $this->assertSame(0, $gift->fresh()->stock);
    }

    public function test_editing_or_archiving_a_promotion_never_changes_a_placed_order(): void
    {
        $size = $this->makeSize(price: 100, stock: 10);
        $promotion = $this->promotion('percent', ['discount_value' => 20], [['product', $size->product_id]]);
        $this->add($size, 1);
        $id = $this->checkout()->assertCreated()->assertJsonPath('total_price', 80)->json('order_id');

        $promotion->update(['discount_value' => 50, 'name_en' => 'Renamed']);
        $promotion->delete();

        $order = Order::find($id);
        $this->assertEquals(80, $order->total_price);
        $this->assertEquals(20, $order->items_discount_amount);
        $snapshot = OrderPromotion::where('order_id', $id)->sole();
        $this->assertSame('Percent', $snapshot->name);
        $this->assertEquals(20, $snapshot->discount_amount);
        $this->assertSame($promotion->id, $snapshot->discount_rule_id, 'Archived, not deleted: the link stays');
        $line = OrderItem::where('order_id', $id)->sole();
        $this->assertEquals(80, $line->price_at_time);
        $this->assertEquals(20, $line->discount_amount);
        $this->assertSame('Percent', $line->promotion_snapshot['promotions'][0]['name']);

        $this->expectException(\LogicException::class);
        $snapshot->update(['discount_amount' => 0]);
    }
}
