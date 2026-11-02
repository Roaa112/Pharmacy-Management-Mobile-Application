<?php

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CouponsGiftsAndPromotionsTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function coupon(array $attributes): Coupon
    {
        return Coupon::create($attributes + ['code' => 'C'.uniqid(), 'discount_value' => 10, 'is_active' => true, 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
    }

    /** Preview with the same address must equal what checkout charges. */
    private function assertPreviewMatchesCheckout(Coupon $coupon, int $addressId): void
    {
        $preview = $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code, 'address_id' => $addressId])->assertOk();
        $order = $this->postJson('/api/user/orders', ['address_id' => $addressId, 'payment_method' => 'cash', 'coupon_code' => $coupon->code])->assertCreated();

        $this->assertEquals($preview->json('total_after_discount'), $order->json('total_price'));
        $this->assertEquals($preview->json('discount'), $order->json('discount'));
    }

    public function test_preview_and_checkout_agree_including_delivery(): void
    {
        $this->makeDeliveryPrice('Cairo', 10);
        $address = $this->makeAddress($this->user);
        $this->putInCart($this->user, $this->makeSize(price: 90, stock: 5), 1);

        // Percent on items + delivery (store default): 10% of 100 = 10.
        $coupon = $this->coupon(['discount_value' => 10]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code, 'address_id' => $address->id])
            ->assertOk()->assertJsonPath('discount', 10)->assertJsonPath('total_after_discount', 90)->assertJsonPath('delivery_fee', 10);

        $this->assertPreviewMatchesCheckout($coupon, $address->id);
    }

    public function test_items_only_coupon_fixed_amount_minimum_and_cap(): void
    {
        $this->makeDeliveryPrice('Cairo', 10);
        $address = $this->makeAddress($this->user);
        $size = $this->makeSize(price: 90, stock: 10);

        $this->putInCart($this->user, $size, 1);
        $this->assertPreviewMatchesCheckout($this->coupon(['discount_value' => 10, 'applies_to_delivery' => false]), $address->id); // 9 off

        $this->putInCart($this->user, $size, 1);
        $fixed = $this->coupon(['type' => 'fixed', 'discount_value' => 15]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $fixed->code])->assertJsonPath('discount', 15);
        $this->assertPreviewMatchesCheckout($fixed, $address->id);

        $this->putInCart($this->user, $size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $this->coupon(['min_order_amount' => 100])->code])
            ->assertStatus(400)->assertJsonPath('code', 'below_minimum');

        $capped = $this->coupon(['discount_value' => 50, 'max_discount_amount' => 20]);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $capped->code])->assertJsonPath('discount', 20);
    }

    public function test_store_setting_decides_delivery_for_coupons_without_their_own_choice(): void
    {
        $this->makeDeliveryPrice('Cairo', 10);
        $address = $this->makeAddress($this->user);
        $this->putInCart($this->user, $this->makeSize(price: 90, stock: 5), 1);
        Setting::current()->update(['coupon_applies_to_delivery' => false]);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $this->coupon(['discount_value' => 10])->code, 'address_id' => $address->id])
            ->assertJsonPath('discount', 9)->assertJsonPath('applies_to_delivery', false);
    }

    public function test_per_user_limit(): void
    {
        $coupon = $this->coupon(['per_user_limit' => 2]);
        $size = $this->makeSize(price: 10, stock: 10);

        foreach ([201, 201] as $status) {
            $this->putInCart($this->user, $size, 1);
            $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash', 'coupon_code' => $coupon->code])->assertStatus($status);
        }

        $this->putInCart($this->user, $size, 1);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => $coupon->code])->assertStatus(400)->assertJsonPath('code', 'already_used');
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash', 'coupon_code' => $coupon->code])
            ->assertStatus(422)->assertJsonPath('code', 'coupon_rejected')->assertJsonPath('reason', 'already_used');
    }

    public function test_flash_sale_end_time_and_one_hour_default(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $start = StoreClock::now();
        $size = $this->makeSize(price: 100);

        $legacy = FlashSale::create(['discount_value' => 50, 'is_active' => true, 'date' => $start->toDateString(), 'time' => $start->format('H:i:s')]);
        $this->assertTrue($legacy->ends_at->eq($start->copy()->addHour()));

        $long = FlashSale::create(['discount_value' => 50, 'is_active' => true, 'date' => $start->toDateString(), 'time' => $start->format('H:i:s'), 'ends_at' => $start->copy()->addHours(5)]);
        $this->assertTrue($long->isLive($start->copy()->addHours(3)));
        $this->assertFalse($long->isLive($start->copy()->addHours(5)));
        $this->assertFalse($legacy->isLive($start->copy()->addHours(3)));
        Carbon::setTestNow();
    }

    public function test_the_most_specific_discount_rule_wins(): void
    {
        $size = $this->makeSize(price: 100);
        $product = $size->product;
        $category = \App\Models\Category::create(['name_ar' => 'ف', 'name_en' => 'Cat', 'image' => 'c.png']);
        $product->update(['category_id' => $category->id]);
        $window = ['starts_at' => StoreClock::now()->subDay(), 'ends_at' => StoreClock::now()->addDay(), 'applies_to_type' => 'x', 'applies_to_id' => 0];

        // Older category rule (30%) and newer product rule (10%): the product rule applies.
        DiscountRule::create(['discount_type' => 'percent', 'discount_value' => 30] + $window)->targets()->create(['target_type' => 'category', 'target_id' => $category->id]);
        DiscountRule::create(['discount_type' => 'percent', 'discount_value' => 10] + $window)->targets()->create(['target_type' => 'product', 'target_id' => $product->id]);
        Product::flushActiveDiscountCache();

        $this->assertEquals(90, Product::find($product->id)->priceForSize($size));
    }

    public function test_a_product_gift_is_a_zero_priced_order_line_that_leaves_stock(): void
    {
        $bought = $this->makeSize(price: 50, stock: 10);
        $gift = $this->makeSize(price: 15, stock: 3);
        $rule = DiscountRule::create(['discount_type' => 'buy_x_get_y', 'min_quantity' => 2, 'gift_quantity' => 1, 'starts_at' => StoreClock::now()->subDay(), 'ends_at' => StoreClock::now()->addDay(), 'applies_to_type' => 'product', 'applies_to_id' => $bought->product_id]);
        $rule->targets()->create(['target_type' => 'product', 'target_id' => $bought->product_id]);
        $rule->targets()->create(['target_type' => 'product', 'target_id' => $gift->product_id, 'is_gift' => true]);

        $this->putInCart($this->user, $bought, 2);
        $id = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('total_price', 100)->json('order_id');

        $line = Order::find($id)->items()->where('is_gift', true)->firstOrFail();
        $this->assertSame($gift->id, $line->product_size_id);
        $this->assertEquals(0, $line->total_price);
        $this->assertSame(2, $gift->fresh()->stock);
        // The gift line is reserved at checkout like any other line (and leaves as a "gift" movement at dispatch).
        $this->assertTrue(\App\Models\StockReservation::where('order_item_id', $line->id)->where('status', 'active')->exists());
        $this->getJson("/api/user/order-details/{$id}")->assertJsonPath('products.1.is_gift', true);

        // Canceling returns the gift too.
        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition(Order::find($id), \App\Enums\OrderStatus::Canceled, 'system');
        $this->assertSame(3, $gift->fresh()->stock);
    }

    public function test_an_out_of_stock_gift_is_not_given(): void
    {
        $bought = $this->makeSize(price: 50, stock: 10);
        $gift = $this->makeSize(price: 15, stock: 0);
        $rule = DiscountRule::create(['discount_type' => 'amount_gift', 'min_amount' => 40, 'starts_at' => StoreClock::now()->subDay(), 'ends_at' => StoreClock::now()->addDay(), 'applies_to_type' => 'product', 'applies_to_id' => $bought->product_id]);
        $rule->targets()->create(['target_type' => 'product', 'target_id' => $bought->product_id]);
        $rule->targets()->create(['target_type' => 'product', 'target_id' => $gift->product_id, 'is_gift' => true]);

        $this->putInCart($this->user, $bought, 1);
        $id = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('gift_description', null)->json('order_id');

        $this->assertFalse(Order::find($id)->items()->where('is_gift', true)->exists());
        $this->assertSame(0, ProductSize::find($gift->id)->stock);
    }
}
