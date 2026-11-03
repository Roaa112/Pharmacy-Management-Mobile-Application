<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** One customer line per size in a cart, enforced by the database; gift lines are separate. */
class CartLineUniquenessTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_adding_the_same_item_twice_merges_into_one_line(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $size = $this->makeSize(price: 20, stock: 5);
        $add = fn (int $qty) => $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => $qty])->assertOk();

        $add(2);
        $add(2);
        $add(9); // capped by stock

        $this->assertSame([5], Cart::where('user_id', $user->id)->pluck('product_quantity')->all());
    }

    public function test_the_database_refuses_a_second_customer_line_for_the_same_size(): void
    {
        $user = User::factory()->create();
        $size = $this->makeSize(price: 20, stock: 5);
        $this->putInCart($user, $size, 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->putInCart($user, $size, 1);
    }

    public function test_gift_lines_and_other_customers_are_not_constrained(): void
    {
        $user = User::factory()->create();
        $size = $this->makeSize(price: 20, stock: 5);
        $this->putInCart($user, $size, 1);
        $this->putInCart(User::factory()->create(), $size, 1);

        // Two gifts of the same size from different rewards, next to the customer's own line.
        foreach (['promo:1', 'promo:2'] as $key) {
            Cart::create(['user_id' => $user->id, 'product_id' => $size->product_id, 'product_size_id' => $size->id, 'product_quantity' => 1,
                'price_at_time' => 0, 'original_price' => 20, 'total_price' => 0, 'is_gift' => true, 'gift_key' => $key]);
        }

        $this->assertSame([1, 2], [Cart::customerLines()->where('user_id', $user->id)->count(), Cart::giftLines()->where('user_id', $user->id)->count()]);
    }

    public function test_changing_a_line_to_a_size_already_in_the_cart_merges_them(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $small = $this->makeSize(price: 20, stock: 5);
        $large = $small->product->sizes()->create(['size' => 'L', 'price' => 30, 'stock' => 5]);
        $this->putInCart($user, $small, 2);
        $this->putInCart($user, $large, 1);

        $this->postJson('/api/user/cart/update-quantity', ['product_id' => $small->product_id, 'old_size_id' => $small->id, 'new_size_id' => $large->id])->assertOk();

        $this->assertSame([[$large->id, 3]], Cart::where('user_id', $user->id)->get()->map(fn ($c) => [$c->product_size_id, $c->product_quantity])->all());
    }
}
