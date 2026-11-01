<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CheckoutTest extends TestCase
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
        return $this->postJson('/api/user/orders', array_merge([
            'address_id' => -1, // pickup from the pharmacy: no delivery fee
            'payment_method' => 'cash',
        ], $overrides));
    }

    public function test_checkout_decrements_stock_once_and_clears_cart(): void
    {
        $size = $this->makeSize(price: 50, stock: 10);
        $this->putInCart($this->user, $size, 3);

        $this->assertEquals(150, $this->checkout()->assertCreated()->json('total_price'));

        $this->assertSame(7, $size->fresh()->stock);
        $this->assertSame(0, Cart::where('user_id', $this->user->id)->count());

        // Re-submitting the same checkout (double tap / retry) must not create a second order.
        $this->checkout()->assertStatus(422);
        $this->assertSame(7, $size->fresh()->stock);
        $this->assertSame(1, Order::count());
    }

    public function test_checkout_uses_catalogue_price_not_the_stored_cart_price(): void
    {
        $size = $this->makeSize(price: 80, stock: 10);
        $this->putInCart($this->user, $size, 2, storedPrice: 1);

        $response = $this->checkout()->assertCreated();

        $this->assertEquals(160, $response->json('total_price'));
        $this->assertDatabaseHas('order_items', ['product_size_id' => $size->id, 'price_at_time' => 80, 'total_price' => 160]);
    }

    public function test_checkout_with_insufficient_stock_changes_nothing(): void
    {
        $size = $this->makeSize(stock: 2);
        $this->putInCart($this->user, $size, 5);

        $this->checkout()->assertStatus(422);

        $this->assertSame(2, $size->fresh()->stock);
        $this->assertSame(0, Order::count());
        $this->assertSame(1, Cart::count());
    }

    public function test_delivery_fee_is_added_for_own_address(): void
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $address = $this->makeAddress($this->user, 'Cairo');
        $this->putInCart($this->user, $this->makeSize(price: 20), 1);

        $this->checkout(['address_id' => $address->id])->assertCreated();

        $this->assertDatabaseHas('orders', ['address_id' => $address->id, 'delivery_fee' => 5, 'total_price' => 25]);
    }

    public function test_city_wildcards_cannot_match_an_arbitrary_delivery_price(): void
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $address = $this->makeAddress($this->user, '%');
        $this->putInCart($this->user, $this->makeSize(), 1);

        $this->checkout(['address_id' => $address->id])->assertStatus(422);
    }

    /** ZainCash and Qi Card were retired: checkout refuses them, even with a receipt. */
    public function test_retired_transfer_methods_are_refused(): void
    {
        Storage::fake('local');
        $this->putInCart($this->user, $this->makeSize(), 1);

        foreach (['zaincash', 'qicard'] as $method) {
            $this->checkout(['payment_method' => $method, 'payment_image' => UploadedFile::fake()->image('receipt.jpg')])
                ->assertStatus(422)->assertJsonValidationErrors('payment_method');
        }

        $this->assertDatabaseCount('orders', 0);
        $this->checkout()->assertCreated();
    }

    public function test_payment_image_must_be_an_image(): void
    {
        Storage::fake('public');
        $this->putInCart($this->user, $this->makeSize(), 1);

        $this->checkout([
            'payment_method' => 'cash',
            'payment_image' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('payment_image');
    }

    public function test_cannot_add_another_products_size_to_the_cart(): void
    {
        $expensive = $this->makeSize(price: 500);
        $cheap = $this->makeSize(price: 1);

        $this->postJson('/api/user/cart', [
            'product_id' => $expensive->product_id,
            'product_size' => $cheap->id,
            'quantity' => 1,
        ])->assertStatus(422);

        $this->assertSame(0, Cart::count());
    }

    public function test_adding_to_cart_twice_never_exceeds_stock(): void
    {
        $size = $this->makeSize(stock: 3);
        $payload = ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 2];

        $this->postJson('/api/user/cart', $payload)->assertOk();
        $this->postJson('/api/user/cart', $payload)->assertOk();

        $this->assertSame(3, Cart::first()->product_quantity);
    }

    public function test_cart_rejects_invalid_quantities(): void
    {
        $size = $this->makeSize();

        foreach ([0, -1, 'abc', 1.5] as $quantity) {
            $this->postJson('/api/user/cart', [
                'product_id' => $size->product_id,
                'product_size' => $size->id,
                'quantity' => $quantity,
            ])->assertStatus(422);
        }
    }

    public function test_coupon_preview_does_not_consume_the_coupon_and_checkout_consumes_it_once(): void
    {
        $coupon = Coupon::create(['code' => 'SAVE10', 'discount_value' => 10, 'is_active' => true, 'once_per_user' => true]);
        $this->putInCart($this->user, $this->makeSize(price: 100), 1);

        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'SAVE10'])->assertOk()->assertJsonPath('discount', 10);
        $this->postJson('/api/user/cart/apply-coupon', ['coupon' => 'SAVE10'])->assertOk();

        $this->assertSame(0, $coupon->fresh()->used_count);

        $this->checkout(['coupon_code' => 'SAVE10'])->assertCreated();

        $this->assertDatabaseHas('orders', ['coupon_id' => $coupon->id, 'total_price' => 90]);
        $this->assertSame(1, $coupon->fresh()->used_count);

        // once_per_user: a second order does not get the discount again.
        $this->putInCart($this->user, $this->makeSize(price: 100), 1);
        $this->assertEquals(100, $this->checkout(['coupon_code' => 'SAVE10'])->assertCreated()->json('total_price'));
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_coupon_usage_limit_is_respected(): void
    {
        $coupon = Coupon::create(['code' => 'ONE', 'discount_value' => 50, 'is_active' => true, 'usage_limit' => 1, 'used_count' => 1]);
        $this->putInCart($this->user, $this->makeSize(price: 100), 1);

        $this->assertEquals(100, $this->checkout(['coupon_code' => 'ONE'])->assertCreated()->json('total_price'));

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_notes_length_is_limited(): void
    {
        $this->putInCart($this->user, $this->makeSize(), 1);

        $this->checkout(['notes' => str_repeat('a', 226)])->assertStatus(422);
    }
}
