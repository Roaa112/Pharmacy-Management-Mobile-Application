<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Modules\Product\Repositories\ProductsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->customer = User::factory()->create();
    }

    private function setStatus(Order $order, string $status)
    {
        return $this->actingAs($this->admin, 'admin')
            ->from('/dashboard/orders')
            ->put("/dashboard/orders/{$order->id}/update-status", ['status' => $status]);
    }

    public function test_cancel_restores_stock_exactly_once(): void
    {
        $size = $this->makeSize(stock: 7);
        $order = $this->makeOrder($this->customer, $size, quantity: 3);

        $this->setStatus($order, 'canceled')->assertSessionHas('success');
        $this->assertSame(10, $size->fresh()->stock);

        $this->setStatus($order, 'canceled')->assertSessionHas('error');
        $this->assertSame(10, $size->fresh()->stock);
    }

    public function test_forward_transitions_are_allowed(): void
    {
        $order = $this->makeOrder($this->customer, $this->makeSize());

        foreach (['confirmed', 'on_delivery', 'completed'] as $status) {
            $this->setStatus($order, $status)->assertSessionHas('success');
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_final_statuses_cannot_be_changed(): void
    {
        $size = $this->makeSize(stock: 5);
        $completed = $this->makeOrder($this->customer, $size, status: 'completed');
        $canceled = $this->makeOrder($this->customer, $size, status: 'canceled');

        $this->setStatus($completed, 'pending')->assertSessionHas('error');
        $this->setStatus($completed, 'canceled')->assertSessionHas('error');
        $this->setStatus($canceled, 'confirmed')->assertSessionHas('error');

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('canceled', $canceled->fresh()->status);
        $this->assertSame(5, $size->fresh()->stock);
    }

    public function test_status_cannot_move_backwards(): void
    {
        $order = $this->makeOrder($this->customer, $this->makeSize(), status: 'on_delivery');

        $this->setStatus($order, 'pending')->assertSessionHas('error');

        $this->assertSame('on_delivery', $order->fresh()->status);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $order = $this->makeOrder($this->customer, $this->makeSize());

        $this->setStatus($order, 'refunded')->assertSessionHasErrors('status');
    }

    public function test_canceled_orders_do_not_count_as_valid_points(): void
    {
        // Without a validity period (0 days) points only count within the same second,
        // which made this test fail whenever the clock ticked (see docs/business-rules.md, D10).
        \App\Models\Setting::create(['points_validity_days' => 30]);
        $size = $this->makeSize();
        $this->earnPoints($this->makeOrder($this->customer, $size), 10);
        $canceled = $this->makeOrder($this->customer, $size);
        $this->earnPoints($canceled, 40);
        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition($canceled, \App\Enums\OrderStatus::Canceled, 'admin');

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/user/points/valid')->assertOk()->assertJsonPath('valid_points_total', 10);
    }

    public function test_editing_product_sizes_keeps_order_history(): void
    {
        $size = $this->makeSize(price: 10, stock: 5);
        $order = $this->makeOrder($this->customer, $size, quantity: 2);
        $product = $size->product;

        // Admin edits the price of the existing size and removes nothing.
        app(ProductsRepository::class)->replaceSizes($product, [
            ['id' => $size->id, 'size' => 'M', 'price' => 12, 'stock' => 5],
        ]);

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertEquals(12, $size->fresh()->price);

        // Admin removes the size entirely: it was sold before, so it is kept (out of stock).
        app(ProductsRepository::class)->replaceSizes($product, [
            ['size' => 'L', 'price' => 20, 'stock' => 3],
        ]);

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(0, $size->fresh()->stock);
        $this->assertSame(2, $product->sizes()->count());
    }
}
