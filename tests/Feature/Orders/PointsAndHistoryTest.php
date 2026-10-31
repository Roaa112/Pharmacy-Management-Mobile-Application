<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class PointsAndHistoryTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->customer = User::factory()->create();
        Setting::create(['points_validity_days' => 30]);
    }

    private function redeem($order)
    {
        return $this->actingAs($this->admin, 'admin')->from('/dashboard/points')->patch("/dashboard/points/{$order->id}/redeem");
    }

    public function test_points_are_redeemed_once(): void
    {
        $order = $this->makeOrder($this->customer, $this->makeSize());
        $order->update(['points_earned' => 10]);

        $this->redeem($order)->assertSessionHas('success');
        $this->redeem($order)->assertSessionHas('error');

        $this->assertSame('redeemed', $order->fresh()->points_status);
    }

    public function test_points_of_canceled_or_expired_orders_cannot_be_redeemed(): void
    {
        $canceled = $this->makeOrder($this->customer, $this->makeSize(), status: 'canceled');
        $canceled->update(['points_earned' => 10]);
        $expired = $this->makeOrder($this->customer, $this->makeSize());
        $expired->forceFill(['points_earned' => 10, 'created_at' => now()->subDays(31)])->save();

        $this->redeem($canceled)->assertSessionHas('error');
        $this->redeem($expired)->assertSessionHas('error');

        $this->actingAs($this->admin, 'admin')->from('/dashboard/points')
            ->patch('/dashboard/dashboard/points/redeem-multiple', ['order_ids' => [$canceled->id, $expired->id]]);

        $this->assertSame('earned', $canceled->fresh()->points_status);
        $this->assertSame('earned', $expired->fresh()->points_status);
    }

    public function test_deleting_a_product_keeps_it_in_order_history_and_hides_it_from_the_shop(): void
    {
        $size = $this->makeSize(stock: 5);
        $order = $this->makeOrder($this->customer, $size);
        $this->putInCart($this->customer, $size, 1);

        $this->actingAs($this->admin, 'admin')->from('/dashboard/products')
            ->delete("/dashboard/products/{$size->product_id}")->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $size->product_id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $size->product_id]);

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/user/order-details/{$order->id}")->assertOk()
            ->assertJsonPath('products.0.product.id', $size->product_id);

        $ids = collect($this->getJson('/api/v1/user/products')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($size->product_id));
        $this->getJson("/api/v1/user/show/{$size->product_id}/product")->assertNotFound();

        // The cart line disappears and the product cannot be added again.
        $this->getJson('/api/user/cart')->assertOk()->assertJsonPath('adjustments.0.reason', 'out_of_stock');
        $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 1])
            ->assertStatus(422);
        $this->assertSame(0, Cart::count());
    }
}
