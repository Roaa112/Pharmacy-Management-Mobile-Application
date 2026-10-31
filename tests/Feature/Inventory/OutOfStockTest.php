<?php

namespace Tests\Feature\Inventory;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * How the mobile API behaves when stock runs out, step by step.
 */
class OutOfStockTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    // ---------- decision D6: sold-out products stay visible, marked out of stock ----------

    public function test_sold_out_product_stays_in_the_catalog_marked_out_of_stock(): void
    {
        $soldOut = $this->makeSize(stock: 0);
        $inStock = $this->makeSize(stock: 3);

        $products = collect($this->getJson('/api/v1/user/products')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($products->has($inStock->product_id));
        $this->assertTrue($products->has($soldOut->product_id));
        $this->assertSame('out_of_stock', $products[$soldOut->product_id]['stock_status']);
        $this->assertFalse($products[$soldOut->product_id]['in_stock']);
        $this->assertSame('in_stock', $products[$inStock->product_id]['stock_status']);
        // Unchanged contract: available_sizes lists only sizes that can be bought.
        $this->assertSame([], $products[$soldOut->product_id]['available_sizes']);
    }

    public function test_entirely_sold_out_product_page_opens_instead_of_404(): void
    {
        $soldOut = $this->makeSize(stock: 0);

        $this->getJson("/api/v1/user/show/{$soldOut->product_id}/product")
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.size_options.0.in_stock', false)
            ->assertJsonPath('data.size_options.0.stock', 0);
    }

    public function test_product_with_one_sold_out_size_shows_both_sizes_with_their_availability(): void
    {
        $inStock = $this->makeSize(stock: 3);
        $soldOutSize = $inStock->product->sizes()->create(['size' => 'L', 'price' => 120, 'stock' => 0]);

        $product = $this->getJson("/api/v1/user/show/{$inStock->product_id}/product")->assertOk()->json('data');
        $options = collect($product['size_options'])->keyBy('id');

        $this->assertSame('in_stock', $product['stock_status']);
        $this->assertTrue($options[$inStock->id]['in_stock']);
        $this->assertFalse($options[$soldOutSize->id]['in_stock']);
        $this->assertEquals(120, $options[$soldOutSize->id]['price']);
        $this->assertSame([$inStock->id], collect($product['available_sizes'])->pluck('id')->all());
    }

    public function test_sold_out_size_of_a_visible_product_cannot_be_added_to_the_cart(): void
    {
        $inStock = $this->makeSize(stock: 3);
        $soldOutSize = $inStock->product->sizes()->create(['size' => 'L', 'price' => 120, 'stock' => 0]);

        $this->postJson('/api/user/cart', ['product_id' => $inStock->product_id, 'product_size' => $soldOutSize->id, 'quantity' => 1])
            ->assertStatus(400);

        $this->assertSame(0, Cart::count());
    }

    public function test_deleted_products_stay_hidden_even_when_in_stock(): void
    {
        $size = $this->makeSize(stock: 5);
        $size->product->delete();

        $ids = collect($this->getJson('/api/v1/user/products')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($size->product_id));
        $this->getJson("/api/v1/user/show/{$size->product_id}/product")->assertNotFound();
        $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 1])
            ->assertStatus(422);
    }

    public function test_checkout_rejects_a_line_that_sold_out_and_changes_nothing(): void
    {
        $ok = $this->makeSize(stock: 5);
        $soldOut = $this->makeSize(stock: 2);
        $this->putInCart($this->user, $ok, 1);
        $this->putInCart($this->user, $soldOut, 1);
        $soldOut->update(['stock' => 0]);

        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonCount(1, 'errors');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $ok->fresh()->stock);
        $this->assertSame(2, Cart::count());
    }

    public function test_adding_a_sold_out_size_gives_a_clear_message_even_if_the_whole_product_sold_out(): void
    {
        $size = $this->makeSize(stock: 0);

        $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 1])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Cart::count());
    }

    public function test_adding_more_than_stock_is_capped_at_stock(): void
    {
        $size = $this->makeSize(stock: 2);

        $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('data.product_quantity', 2);
    }

    public function test_viewing_the_cart_removes_sold_out_lines_and_reports_it(): void
    {
        $soldOut = $this->makeSize(stock: 3);
        $ok = $this->makeSize(price: 10, stock: 5);
        $this->putInCart($this->user, $soldOut, 2);
        $this->putInCart($this->user, $ok, 1);
        $soldOut->update(['stock' => 0]);

        $response = $this->getJson('/api/user/cart')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(10, $response->json('total_price'));
        $this->assertSame('out_of_stock', $response->json('adjustments.0.reason'));
        $this->assertSame(1, Cart::count());
    }

    public function test_viewing_the_cart_reduces_quantity_to_stock_and_reports_it(): void
    {
        $size = $this->makeSize(price: 10, stock: 5);
        $this->putInCart($this->user, $size, 4);
        $size->update(['stock' => 2]);

        $response = $this->getJson('/api/user/cart')->assertOk();

        $this->assertSame(2, $response->json('data.0.quantity'));
        $this->assertEquals(20, $response->json('total_price'));
        $this->assertSame('quantity_reduced', $response->json('adjustments.0.reason'));
        $this->assertSame(2, $response->json('adjustments.0.available_quantity'));
    }

    public function test_cart_without_problems_has_no_adjustments_key(): void
    {
        $this->putInCart($this->user, $this->makeSize(stock: 5), 1);

        $this->assertArrayNotHasKey('adjustments', $this->getJson('/api/user/cart')->assertOk()->json());
    }

    public function test_updating_a_sold_out_line_is_refused_instead_of_saving_quantity_zero(): void
    {
        $size = $this->makeSize(stock: 3);
        $size->product->sizes()->create(['size' => 'L', 'price' => 50, 'stock' => 4]); // product still visible
        $item = $this->putInCart($this->user, $size, 2);
        $size->update(['stock' => 0]);

        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $size->product_id, 'operation' => 'decrement'])
            ->assertStatus(400);

        $this->assertSame(2, $item->fresh()->product_quantity);
    }

    public function test_updating_a_line_whose_whole_product_sold_out_is_refused_not_a_server_error(): void
    {
        $size = $this->makeSize(stock: 3);
        $this->putInCart($this->user, $size, 1);
        $size->update(['stock' => 0]);

        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $size->product_id, 'operation' => 'increment'])
            ->assertStatus(400);
    }

    public function test_switching_a_sold_out_line_to_an_available_size_works(): void
    {
        $old = $this->makeSize(price: 10, stock: 3);
        $new = $old->product->sizes()->create(['size' => 'L', 'price' => 15, 'stock' => 4]);
        $item = $this->putInCart($this->user, $old, 2);
        $old->update(['stock' => 0]);

        $this->postJson('/api/user/cart/update-quantity', [
            'product_id' => $old->product_id, 'old_size_id' => $old->id, 'new_size_id' => $new->id,
        ])->assertOk();

        $item->refresh();
        $this->assertSame($new->id, $item->product_size_id);
        $this->assertEquals(30, $item->total_price);
    }

    public function test_the_last_unit_can_only_be_bought_once(): void
    {
        $size = $this->makeSize(stock: 1);
        $other = User::factory()->create();
        $this->putInCart($this->user, $size, 1);
        $this->putInCart($other, $size, 1);

        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated();

        Sanctum::actingAs($other);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertStatus(422);

        $this->assertSame(0, $size->fresh()->stock);
        $this->assertSame(1, Order::count());
    }

    public function test_order_history_still_shows_a_product_after_it_sells_out(): void
    {
        $size = $this->makeSize(stock: 1);
        $order = $this->makeOrder($this->user, $size);
        $size->update(['stock' => 0]);

        $this->getJson("/api/user/order-details/{$order->id}")
            ->assertOk()
            ->assertJsonPath('products.0.product.id', $size->product_id);
    }
}
