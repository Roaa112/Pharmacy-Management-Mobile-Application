<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
    }

    public function test_checkout_records_one_sale_movement_per_line(): void
    {
        $user = User::factory()->create();
        $a = $this->makeSize(stock: 10);
        $b = $this->makeSize(stock: 4);
        $this->putInCart($user, $a, 3);
        $this->putInCart($user, $b, 1);
        Sanctum::actingAs($user);

        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated();

        // Checkout reserves the units (they stay on hand until dispatch): one "reservation" row per line.
        $movement = StockMovement::where('product_size_id', $a->id)->where('type', StockMovement::TYPE_RESERVATION)->sole();
        $this->assertSame('reserved', $movement->bucket);
        $this->assertSame([0, 3, 3], [$movement->quantity_before, $movement->quantity_change, $movement->quantity_after]);
        $this->assertSame($user->id, $movement->user_id);
        $reservation = \App\Models\StockReservation::where('product_size_id', $a->id)->sole();
        $this->assertSame((new \App\Models\StockReservation)->getMorphClass(), $movement->reference_type);
        $this->assertSame($reservation->id, $movement->reference_id);
        $this->assertSame(OrderItem::where('product_size_id', $a->id)->value('id'), $reservation->order_item_id);
        $this->assertSame(7, $a->fresh()->stock, 'sellable stock drops at checkout');
        $this->assertSame(2, StockMovement::where('type', StockMovement::TYPE_RESERVATION)->count());
    }

    public function test_cancellation_is_recorded_once_and_the_ledger_matches_stock(): void
    {
        $size = $this->makeSize(stock: 7);
        $order = $this->makeOrder(User::factory()->create(), $size, quantity: 3);

        $cancel = fn () => $this->actingAs($this->admin, 'admin')->from('/dashboard/orders')
            ->put("/dashboard/orders/{$order->id}/update-status", ['status' => 'canceled']);

        $cancel()->assertSessionHas('success');
        $cancel()->assertSessionHas('error');

        $movement = StockMovement::where('type', StockMovement::TYPE_CANCELLATION)->sole();
        $this->assertSame([7, 3, 10], [$movement->quantity_before, $movement->quantity_change, $movement->quantity_after]);
        $this->assertSame($this->admin->id, $movement->admin_id);
        $this->assertSame(10, $size->fresh()->stock);
    }

    public function test_database_refuses_a_second_movement_for_the_same_order_line(): void
    {
        $size = $this->makeSize(stock: 5);
        $item = $this->makeOrder(User::factory()->create(), $size)->items()->first();

        ProductSize::moveStock($size->id, 1, StockMovement::TYPE_CANCELLATION, ['reference' => $item]);

        // The second identical event is a no-op (idempotency key; unique in the database as a backstop).
        $this->assertNull(ProductSize::moveStock($size->id, 1, StockMovement::TYPE_CANCELLATION, ['reference' => $item]));

        $this->assertSame(6, $size->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_CANCELLATION)->count());

        try {
            StockMovement::create(StockMovement::where('type', StockMovement::TYPE_CANCELLATION)->sole()->only([
                'product_id', 'product_size_id', 'warehouse_id', 'type', 'bucket', 'quantity_before', 'quantity_change', 'quantity_after', 'idempotency_key',
            ]));
            $this->fail('The database must refuse a second row with the same idempotency key.');
        } catch (QueryException) {
        }
    }

    public function test_stock_can_never_go_below_zero(): void
    {
        $size = $this->makeSize(stock: 2);

        $this->expectException(\App\Modules\Inventory\Services\InsufficientStock::class);

        try {
            ProductSize::moveStock($size->id, -3, StockMovement::TYPE_ADJUSTMENT);
        } finally {
            $this->assertSame(2, $size->fresh()->stock);
            // Only the opening stock row: the refused change wrote nothing.
            $this->assertSame([StockMovement::TYPE_INITIAL], StockMovement::pluck('type')->all());
        }
    }

    private function createProductAsAdmin(array $sizes)
    {
        Storage::fake('public');
        $category = Category::create(['name_ar' => 'فئة', 'name_en' => 'Category', 'image' => 'category/c.png']);

        return $this->actingAs($this->admin, 'admin')->post('/dashboard/products', [
            'name_en' => 'Paracetamol', 'name_ar' => 'باراسيتامول', 'category_id' => $category->id,
            'image' => UploadedFile::fake()->image('p.jpg'), 'sizes' => $sizes,
        ]);
    }

    public function test_creating_a_product_records_opening_stock_and_keeps_zero_stock_sizes(): void
    {
        $this->createProductAsAdmin([
            ['size' => '500mg', 'price' => '12.50', 'stock' => 20],
            ['size' => '1g', 'price' => '20.00', 'stock' => 0],
        ])->assertRedirect();

        $product = Product::withoutGlobalScopes()->sole();
        $this->assertSame(['500mg' => 20, '1g' => 0], $product->sizes()->pluck('stock', 'size')->all());

        $opening = StockMovement::sole();
        $this->assertSame([StockMovement::TYPE_INITIAL, 0, 20, 20], [$opening->type, $opening->quantity_before, $opening->quantity_change, $opening->quantity_after]);
        $this->assertSame($this->admin->id, $opening->admin_id);
    }

    private function editSizes(Product $product, array $sizes)
    {
        return $this->actingAs($this->admin, 'admin')->from('/dashboard/products')->put("/dashboard/products/{$product->id}", [
            'name_en' => 'Paracetamol', 'name_ar' => 'باراسيتامول', 'category_id' => $product->category_id
                ?? Category::create(['name_ar' => 'فئة', 'name_en' => 'C', 'image' => 'c.png'])->id,
            'sizes' => $sizes,
        ]);
    }

    public function test_editing_a_product_does_not_overwrite_sales_made_while_the_form_was_open(): void
    {
        $size = $this->makeSize(stock: 10);

        // Admin opens the form (stock 10). Meanwhile a customer buys 3.
        ProductSize::moveStock($size->id, -3, StockMovement::TYPE_SALE);

        // Admin only fixes the price and saves the form with the stale stock value.
        $this->editSizes($size->product, [
            ['id' => $size->id, 'size' => 'M', 'price' => 110, 'stock' => 10, 'stock_original' => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, $size->fresh()->stock);
        $this->assertEquals(110, $size->fresh()->price);
        $this->assertSame(0, StockMovement::where('type', StockMovement::TYPE_ADJUSTMENT)->count());
    }

    public function test_editing_stock_applies_the_admins_change_to_current_stock(): void
    {
        $size = $this->makeSize(stock: 10);
        ProductSize::moveStock($size->id, -3, StockMovement::TYPE_SALE); // now 7

        // Admin received 50 units: changes 10 -> 60 in the form.
        $this->editSizes($size->product, [
            ['id' => $size->id, 'size' => 'M', 'price' => 100, 'stock' => 60, 'stock_original' => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame(57, $size->fresh()->stock);
        $adjustment = StockMovement::where('type', StockMovement::TYPE_ADJUSTMENT)->sole();
        $this->assertSame([7, 50, 57], [$adjustment->quantity_before, $adjustment->quantity_change, $adjustment->quantity_after]);
        $this->assertSame($this->admin->id, $adjustment->admin_id);
    }

    public function test_a_stale_reduction_that_would_go_negative_is_rejected(): void
    {
        $size = $this->makeSize(stock: 5);
        ProductSize::moveStock($size->id, -4, StockMovement::TYPE_SALE); // now 1

        $this->editSizes($size->product, [
            ['id' => $size->id, 'size' => 'M', 'price' => 100, 'stock' => 2, 'stock_original' => 5], // -3
        ])->assertSessionHasErrors('sizes');

        $this->assertSame(1, $size->fresh()->stock);
    }

    public function test_removing_a_sold_size_zeroes_it_through_the_ledger(): void
    {
        $size = $this->makeSize(stock: 4);
        $this->makeOrder(User::factory()->create(), $size);

        $this->editSizes($size->product, [
            ['size' => 'XL', 'price' => 99, 'stock' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $size->fresh()->stock);
        $this->assertSame(1, StockMovement::where(['product_size_id' => $size->id, 'type' => StockMovement::TYPE_ADJUSTMENT])->count());
        // The removed size's opening stock and the new size's opening stock.
        $this->assertSame(2, StockMovement::where('type', StockMovement::TYPE_INITIAL)->count());
        $this->assertSame(3, ProductSize::where('size', 'XL')->sole()->stock);
    }
}
