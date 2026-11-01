<?php

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\FlashSale;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Decision D7: listing, details, cart and checkout charge one price, from one calculation.
 *
 * The store clock is Africa/Cairo (UTC+3 on these June dates, Egyptian summer time); flash
 * sale dates are local wall-clock times.
 * Test times are given in UTC and the sale at the matching local time.
 */
class FlashSalePricingTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private ProductSize $size;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pharmacy.timezone' => 'Africa/Cairo']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->size = $this->makeSize(price: 100, stock: 50);
    }

    /** A 20% flash sale starting 2026-06-01 12:00 store time (09:00 UTC), ending 13:00. */
    private function attachFlashSale(bool $active = true, float $percent = 20): FlashSale
    {
        $sale = FlashSale::create(['discount_value' => $percent, 'is_active' => $active, 'date' => '2026-06-01', 'time' => '12:00:00']);
        $this->size->product->update(['saleable_type' => FlashSale::class, 'saleable_id' => $sale->id]);

        return $sale;
    }

    private function atUtc(string $time): void
    {
        $this->travelTo(Carbon::parse("2026-06-01 {$time}", 'UTC'));
    }

    /**
     * The price every customer-facing step shows or charges for $this->size, right now.
     */
    private function pricesEverywhere(): array
    {
        $productId = $this->size->product_id;

        $listed = collect($this->getJson('/api/v1/user/products')->assertOk()->json('data'))->firstWhere('id', $productId);
        $details = $this->getJson("/api/v1/user/show/{$productId}/product")->assertOk()->json('data');

        Cart::query()->delete();
        $this->postJson('/api/user/cart', ['product_id' => $productId, 'product_size' => $this->size->id, 'quantity' => 1])->assertOk();
        $cart = $this->getJson('/api/user/cart')->assertOk()->json();

        $order = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json();
        $line = OrderItem::where('order_id', $order['order_id'])->sole();

        // Put the stock back for the next call (test helper; not a business operation).
        ProductSize::whereKey($this->size->id)->update(['stock' => 50]);

        return [
            'listing.final_price' => (float) $listed['final_price'],
            'listing.available_sizes' => (float) $listed['available_sizes'][0]['price'],
            'details.final_price' => (float) $details['final_price'],
            'details.available_sizes' => (float) $details['available_sizes'][0]['price'],
            'details.size_options' => (float) $details['size_options'][0]['price'],
            'cart.line' => (float) $cart['data'][0]['discounted_price'],
            'cart.total' => (float) $cart['total_price'],
            'order.price_at_time' => (float) $line->price_at_time,
            'order.total' => (float) $order['total_price'],
        ];
    }

    private function assertPriceEverywhere(float $expected): void
    {
        foreach ($this->pricesEverywhere() as $where => $price) {
            $this->assertEquals($expected, $price, "Wrong price at {$where}");
        }
    }

    public function test_before_the_start_the_regular_price_applies_everywhere(): void
    {
        $this->attachFlashSale();
        $this->atUtc('08:59:59'); // 11:59:59 store time

        $this->assertPriceEverywhere(100);
    }

    public function test_exactly_at_the_start_the_flash_price_applies_everywhere(): void
    {
        $this->attachFlashSale();
        $this->atUtc('09:00:00');

        $this->assertPriceEverywhere(80);
    }

    public function test_during_the_sale_the_flash_price_applies_everywhere(): void
    {
        $this->attachFlashSale();
        $this->atUtc('09:59:59');

        $this->assertPriceEverywhere(80);
    }

    public function test_exactly_at_the_end_the_sale_is_over_everywhere(): void
    {
        $this->attachFlashSale();
        $this->atUtc('10:00:00');

        $this->assertPriceEverywhere(100);
    }

    public function test_after_the_end_the_regular_price_applies_everywhere(): void
    {
        $this->attachFlashSale();
        $this->travelTo(Carbon::parse('2026-06-02 09:30:00', 'UTC')); // same hour, next day

        $this->assertPriceEverywhere(100);
    }

    public function test_a_disabled_flash_sale_never_applies_even_inside_its_window(): void
    {
        $this->attachFlashSale(active: false);
        $this->atUtc('09:30:00');

        $this->assertPriceEverywhere(100);
    }

    public function test_the_window_uses_the_store_timezone_not_utc(): void
    {
        $this->attachFlashSale();

        // 12:30 UTC is 15:30 in Cairo: outside the 12:00–13:00 local window.
        $this->atUtc('12:30:00');
        $this->assertPriceEverywhere(100);

        // Another store timezone moves the window with it.
        config(['pharmacy.timezone' => 'UTC']);
        $this->assertPriceEverywhere(80);
    }

    public function test_outside_the_flash_window_the_regular_discount_rule_applies_without_stacking(): void
    {
        $this->attachFlashSale(percent: 20);
        $this->discountRule('percent', 10);

        $this->atUtc('09:30:00');
        $this->assertPriceEverywhere(80); // flash only, not 80 − 10%

        $this->atUtc('11:00:00');
        $this->assertPriceEverywhere(90); // rule only
    }

    public function test_a_stale_cart_price_or_a_client_sent_price_is_ignored(): void
    {
        $this->attachFlashSale();
        $this->atUtc('09:30:00');

        // Added during the sale at 80, checked out after it ended.
        $this->postJson('/api/user/cart', ['product_id' => $this->size->product_id, 'product_size' => $this->size->id, 'quantity' => 2])->assertOk();
        $this->assertEquals(80, Cart::sole()->price_at_time);

        $this->atUtc('10:30:00');
        $order = $this->postJson('/api/user/orders', [
            'address_id' => -1, 'payment_method' => 'cash', 'total_price' => 1, 'price_at_time' => 1, 'price' => 1,
        ])->assertCreated()->json();

        $this->assertEquals(200, $order['total_price']);
        $this->assertEquals(100, OrderItem::sole()->price_at_time);
    }

    public function test_the_coupon_preview_and_checkout_agree_and_use_live_prices(): void
    {
        $this->attachFlashSale();
        // A coupon does not stack on a flash sale price unless it is configured to.
        Coupon::create(['code' => 'PLAIN', 'discount_value' => 10, 'is_active' => true,
            'start_at' => '2026-06-01 11:00:00', 'end_at' => '2026-06-01 13:00:00']);
        Coupon::create(['code' => 'TEN', 'discount_value' => 10, 'is_active' => true, 'combines_with_promotions' => true,
            'start_at' => '2026-06-01 11:00:00', 'end_at' => '2026-06-01 13:00:00']); // store time

        $this->atUtc('09:30:00');
        $this->postJson('/api/user/cart', ['product_id' => $this->size->product_id, 'product_size' => $this->size->id, 'quantity' => 1])->assertOk();
        Cart::query()->update(['price_at_time' => 1, 'total_price' => 1]); // tampered / stale row

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'PLAIN'])->assertStatus(400)->assertJsonPath('code', 'no_eligible_items');

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'TEN'])
            ->assertOk()
            ->assertJsonPath('original_total', 80)
            ->assertJsonPath('total_after_discount', 72);

        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash', 'coupon_code' => 'TEN'])
            ->assertCreated()
            ->assertJsonPath('total_price', 72);
    }

    public function test_coupon_dates_use_the_store_clock_in_preview_and_checkout(): void
    {
        // Starts 12:00 store time = 09:00 UTC.
        Coupon::create(['code' => 'NOON', 'discount_value' => 10, 'is_active' => true, 'start_at' => '2026-06-01 12:00:00']);
        $this->putInCart($this->user, $this->size, 1);

        $this->atUtc('08:59:00');
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'NOON'])->assertStatus(400);

        $this->atUtc('09:00:00');
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'NOON'])->assertOk();
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash', 'coupon_code' => 'NOON'])
            ->assertCreated()
            ->assertJsonPath('total_price', 90);
    }

    public function test_variants_are_priced_individually_with_the_same_promotion(): void
    {
        $this->attachFlashSale(percent: 15);
        $large = $this->size->product->sizes()->create(['size' => 'L', 'price' => 33.33, 'stock' => 5]);
        $this->atUtc('09:30:00');

        $sizes = collect($this->getJson("/api/v1/user/show/{$this->size->product_id}/product")->json('data.available_sizes'))->keyBy('id');

        $this->assertEquals(85, $sizes[$this->size->id]['price']);
        $this->assertEquals(28.33, $sizes[$large->id]['price']); // 28.3305 rounded half up to the cent

        $this->postJson('/api/user/cart', ['product_id' => $large->product_id, 'product_size' => $large->id, 'quantity' => 3])->assertOk();
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('total_price', 84.99);
    }

    public function test_historical_orders_keep_their_price_and_name_after_the_product_changes(): void
    {
        $this->atUtc('09:30:00');
        $this->putInCart($this->user, $this->size, 2);
        $orderId = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id');

        $this->size->update(['price' => 999, 'size' => 'XXL']);
        $this->size->product->update(['name_en' => 'Renamed', 'name_ar' => 'جديد']);
        $this->size->product->delete();

        $line = OrderItem::sole();
        $this->assertEquals(100, $line->price_at_time);
        $this->assertEquals(200, $line->total_price);
        $this->assertSame('Product', $line->product_name_en);
        $this->assertSame('M', $line->size_label);
        $this->assertEquals(200, Order::find($orderId)->total_price);

        $details = $this->getJson("/api/user/order-details/{$orderId}")->assertOk();
        // Decimals come back as numbers on SQLite and as "100.00" strings on MySQL/MariaDB.
        $this->assertEquals(100, $details->json('products.0.price_at_time'));
        $this->assertEquals(200, $details->json('total_price'));
        $this->assertSame('M', $details->json('products.0.size'));
        $this->assertSame('Product', $details->json('products.0.product_name'));
    }

    private function discountRule(string $type, $value): void
    {
        $ruleId = DB::table('discount_rules')->insertGetId([
            'discount_type' => $type, 'discount_value' => $value, 'applies_to_type' => 'product',
            'applies_to_id' => $this->size->product_id,
            'starts_at' => '2026-05-01 00:00:00', 'ends_at' => '2026-07-01 00:00:00',
        ]);
        DB::table('discount_rule_targets')->insert(['discount_rule_id' => $ruleId, 'target_type' => 'product', 'target_id' => $this->size->product_id]);
    }
}
