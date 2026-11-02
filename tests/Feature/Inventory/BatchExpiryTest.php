<?php

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\BatchBalance;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PurchasingService;
use App\Modules\Inventory\Services\TransferService;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Support\StoreClock;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Batch (lot) and expiry tracking through purchasing, checkout, dispatch, cancellation, returns,
 * transfers and the daily expiry job. Every test ends with the inventory invariants intact
 * (batch balances add up to the balances, ledger and projections agree).
 */
class BatchExpiryTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $this->supplier = Supplier::create(['code' => 'SUP', 'name' => 'Supplier']);
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $this->assertSame([], app(InventoryIntegrity::class)->problems(), 'inventory invariants');
        parent::tearDown();
    }

    /** Receive lots of one SKU: [lot, expiry (days from today), units]. */
    private function receive(ProductSize $size, array $lots): void
    {
        $purchasing = app(PurchasingService::class);
        $order = $purchasing->createOrder($this->supplier, Warehouse::default()->id,
            [['product_size_id' => $size->id, 'quantity' => array_sum(array_column($lots, 2)), 'unit_cost' => 10]], [], $this->admin->id);
        $purchasing->markOrdered($order, $this->admin->id);
        $item = $order->items->first();

        foreach ($lots as [$lot, $days, $units]) {
            $purchasing->receive($order->fresh(), [$item->id => ['received' => $units, 'lot_number' => $lot, 'expiry_date' => StoreClock::now()->addDays($days)->toDateString()]], null, null, $this->admin->id);
        }
    }

    private function order(ProductSize $size, int $quantity): Order
    {
        $this->putInCart($this->user, $size, $quantity);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    private function batch(string $lot): InventoryBatch
    {
        return InventoryBatch::where('lot_number', $lot)->sole();
    }

    private function units(string $lot, string $bucket = 'on_hand'): int
    {
        return (int) BatchBalance::where('inventory_batch_id', $this->batch($lot)->id)->sum($bucket);
    }

    public function test_received_lots_are_reserved_and_dispatched_first_expiry_first_out(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['LATE', 300, 5], ['SOON', 60, 3]]);
        $this->assertSame(8, $size->fresh()->stock);

        $order = $this->order($size, 4);
        $reservations = StockReservation::where('status', 'active')->orderBy('id')->get();
        $this->assertSame([[$this->batch('SOON')->id, 3], [$this->batch('LATE')->id, 1]],
            $reservations->map(fn ($r) => [$r->inventory_batch_id, $r->quantity])->all());

        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, 'system');
        $this->assertSame([0, 4], [$this->units('SOON'), $this->units('LATE')]);

        // The ledger says which lots left.
        $this->assertEqualsCanonicalizing([-3, -1], StockMovement::where('type', StockMovement::TYPE_FULFILLMENT)->where('bucket', 'on_hand')
            ->with('batchLines')->get()->flatMap->batchLines->pluck('quantity_change')->all());
    }

    public function test_expired_and_short_dated_units_are_never_sold(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['OLD', 5, 2], ['GOOD', 200, 1]]);
        $this->assertSame(3, $size->fresh()->stock);

        // Six days later the OLD lot has expired: only the GOOD unit is for sale.
        $this->travel(6)->days();
        app(InventoryService::class)->refreshProjection($size = $size->fresh());
        $this->assertSame(1, $size->fresh()->stock);

        $this->putInCart($this->user, $size, 2);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertStatus(422);

        // A minimum shelf life excludes the GOOD lot too once it is within it (the daily job refreshes the catalogue).
        config(['pharmacy.min_shelf_life_days' => 365]);
        $this->artisan('inventory:expire-batches')->assertSuccessful();
        $this->assertSame(0, $size->fresh()->stock);
    }

    public function test_an_expired_lot_cannot_be_received(): void
    {
        $size = $this->makeSize(stock: 0);

        $this->expectException(DomainException::class);
        $this->receive($size, [['PAST', 0, 3]]);
    }

    public function test_dispatch_never_ships_an_expired_lot(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['SOON', 3, 2], ['LATE', 300, 2]]);
        $order = $this->order($size, 2);
        $this->assertSame($this->batch('SOON')->id, StockReservation::where('status', 'active')->value('inventory_batch_id'));

        // The reserved lot expires before the order is dispatched: dispatch takes the LATE lot instead.
        $this->travel(4)->days();
        app(OrderWorkflow::class)->transition($order, OrderStatus::OnDelivery, 'system');
        $this->assertSame([2, 0], [$this->units('SOON'), $this->units('LATE')]);
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_BATCH_REALLOCATION)->count());

        // No unexpired units left: the next dispatch is refused instead of shipping expired medicine.
        $second = Order::create(['user_id' => $this->user->id, 'payment_method' => 'cash', 'delivery_fee' => 0, 'total_price' => 100, 'points_earned' => 0, 'status' => 'pending']);
        $line = $second->items()->create(['product_id' => $size->product_id, 'product_size_id' => $size->id, 'quantity' => 1, 'price_at_time' => 50, 'original_price' => 50, 'total_price' => 50]);
        StockReservation::create(['order_item_id' => $line->id, 'product_size_id' => $size->id, 'warehouse_id' => Warehouse::default()->id,
            'inventory_batch_id' => $this->batch('SOON')->id, 'quantity' => 1, 'status' => 'active']);
        app(InventoryService::class)->apply($size->id, Warehouse::default()->id, ['reserved' => 1], StockMovement::TYPE_RESERVATION,
            ['batches' => ['reserved' => [$this->batch('SOON')->id => 1]]], 'test-reservation');
        $line->forceFill(['stock_state' => 'reserved'])->save();

        try {
            app(OrderWorkflow::class)->transition($second, OrderStatus::OnDelivery, 'system');
            $this->fail('An expired lot must not be dispatched.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        app(OrderWorkflow::class)->transition($second, OrderStatus::Canceled, 'system');
    }

    public function test_cancellation_after_dispatch_and_returns_go_back_to_their_own_lot(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['A', 100, 2], ['B', 200, 5]]);
        $order = $this->order($size, 3); // A:2, B:1
        app(OrderWorkflow::class)->transition($order, OrderStatus::OnDelivery, 'system');

        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, 'system');
        $this->assertSame([2, 1], [$this->units('A', 'quarantined'), $this->units('B', 'quarantined')]);

        // Inspection of lot A puts it back on sale in lot A.
        app(InventoryService::class)->inspectQuarantine($size->id, Warehouse::default()->id, 2, 0, 0, 'Sealed packs', $this->admin->id, $this->batch('A')->id);
        $this->assertSame([2, 0], [$this->units('A'), $this->units('A', 'quarantined')]);
    }

    public function test_transfers_move_lots_between_warehouses(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['A', 100, 2], ['B', 200, 5]]);
        $branch = Warehouse::create(['code' => 'BR', 'name' => 'Branch', 'is_active' => true, 'is_sellable' => true]);
        $transfers = app(TransferService::class);

        $transfer = $transfers->request(Warehouse::default()->id, $branch->id, [$size->id => 3], null, $this->admin->id);
        $transfers->approve($transfer, $this->admin->id);
        $transfers->dispatch($transfer, $this->admin->id);
        $transfers->receive($transfer->fresh(), [$transfer->items->first()->id => 3], null, $this->admin->id);

        $at = fn (string $lot, Warehouse $w) => (int) BatchBalance::where('warehouse_id', $w->id)->where('inventory_batch_id', $this->batch($lot)->id)->value('on_hand');
        $this->assertSame([0, 4], [$at('A', Warehouse::default()), $at('B', Warehouse::default())]);
        $this->assertSame([2, 1], [$at('A', $branch), $at('B', $branch)]);
    }

    public function test_the_daily_job_takes_expired_units_off_sale_but_keeps_reserved_ones(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['OLD', 2, 4]]);
        $order = $this->order($size, 1);

        $this->travel(3)->days();
        $this->artisan('inventory:expire-batches')->assertSuccessful();

        $this->assertSame([1, 1, 3], [$this->units('OLD'), $this->units('OLD', 'reserved'), $this->units('OLD', 'damaged')]);
        $this->artisan('inventory:expire-batches')->assertSuccessful(); // idempotent
        $this->assertSame(3, $this->units('OLD', 'damaged'));

        app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, 'system');
    }

    public function test_stock_created_before_batches_lives_in_the_legacy_batch_and_stays_sellable(): void
    {
        $size = $this->makeSize(price: 50, stock: 6);

        $legacy = InventoryBatch::where('product_size_id', $size->id)->sole();
        $this->assertTrue($legacy->is_legacy);
        $this->assertNull($legacy->expiry_date);
        $this->assertSame(6, (int) BatchBalance::where('inventory_batch_id', $legacy->id)->sum('on_hand'));

        $this->order($size, 2);
        $this->assertSame(4, $size->fresh()->stock);
    }

    // ------------------------------------------------------------------ catalogue stock edits (setStock / moveStock)

    public function test_lowering_catalogue_stock_never_takes_short_dated_units(): void
    {
        config(['pharmacy.min_shelf_life_days' => 30]);
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['SHORT', 18, 3], ['LONG', 240, 10]]);
        app(InventoryService::class)->refreshProjection($size = $size->fresh());
        $this->assertSame(10, $size->fresh()->stock, 'SHORT is inside the shelf life: not for sale');

        ProductSize::setStock($size->id, 7, StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Admin edit']);

        $this->assertSame(7, $size->fresh()->stock);
        $this->assertSame([3, 7], [$this->units('SHORT'), $this->units('LONG')]);
    }

    public function test_lowering_catalogue_stock_never_takes_expired_units(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['OLD', 2, 4], ['NEW', 200, 6]]);
        $this->travel(3)->days(); // OLD expired; the nightly job has not moved it yet
        app(InventoryService::class)->refreshProjection($size = $size->fresh());
        $this->assertSame(6, $size->fresh()->stock);

        ProductSize::moveStock($size->id, -2, StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Admin edit']);

        $this->assertSame([4, 4, 4], [$this->units('OLD'), $this->units('NEW'), (int) $size->fresh()->stock]);
    }

    public function test_lowering_catalogue_stock_with_only_sellable_lots_is_first_expiry_first_out(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['B', 300, 5], ['A', 100, 5]]);

        ProductSize::setStock($size->id, 7, StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Admin edit']);

        $this->assertSame([2, 5, 7], [$this->units('A'), $this->units('B'), (int) $size->fresh()->stock]);
    }

    public function test_lowering_catalogue_stock_below_what_is_sellable_is_refused(): void
    {
        config(['pharmacy.min_shelf_life_days' => 30]);
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['SHORT', 18, 3], ['LONG', 240, 2]]);

        $this->expectException(\App\Modules\Inventory\Services\InsufficientStock::class);
        ProductSize::moveStock($size->id, -4, StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Admin edit']); // only 2 sellable
    }

    public function test_a_physical_correction_still_takes_from_any_lot_first_expiry_first(): void
    {
        config(['pharmacy.min_shelf_life_days' => 30]);
        $size = $this->makeSize(price: 50, stock: 0);
        $this->receive($size, [['SHORT', 18, 3], ['LONG', 240, 10]]);

        // Inventory screen "adjust": physical units missing from the shelf, unchanged behaviour.
        app(InventoryService::class)->adjust($size->id, Warehouse::default()->id, -2, 'Shelf count', $this->admin->id);

        $this->assertSame([1, 10], [$this->units('SHORT'), $this->units('LONG')]);
    }

    // ------------------------------------------------------------------ legacy and undated stock

    public function test_legacy_stock_is_sold_before_newer_dated_lots(): void
    {
        $size = $this->makeSize(price: 50, stock: 3); // from before batch tracking: expiry unknown
        $this->receive($size, [['NEW', 200, 5]]);
        $legacy = InventoryBatch::where('product_size_id', $size->id)->where('is_legacy', true)->sole();

        $this->order($size, 4);

        $this->assertSame([[$legacy->id, 3], [$this->batch('NEW')->id, 1]],
            StockReservation::where('status', 'active')->orderBy('id')->get()->map(fn ($r) => [$r->inventory_batch_id, $r->quantity])->all());
        $this->assertNull($legacy->fresh()->expiry_date, 'no expiry is invented for legacy stock');
        $this->assertStringContainsString('expiry unknown', $legacy->label());
    }

    public function test_goods_received_without_lot_or_expiry_get_their_own_batch_sold_after_dated_lots(): void
    {
        $size = $this->makeSize(price: 50, stock: 0);
        $purchasing = app(PurchasingService::class);
        $po = $purchasing->createOrder($this->supplier, Warehouse::default()->id, [['product_size_id' => $size->id, 'quantity' => 6, 'unit_cost' => 10]], [], $this->admin->id);
        $purchasing->markOrdered($po, $this->admin->id);
        $purchasing->receive($po->fresh(), [$po->items->first()->id => ['received' => 3]], null, null, $this->admin->id);
        $this->receive($size, [['DATED', 200, 3]]);

        $undated = InventoryBatch::where('product_size_id', $size->id)->whereNull('expiry_date')->sole();
        $this->assertFalse($undated->is_legacy, 'not mixed into the pre-tracking batch');
        $this->assertNotNull($undated->purchase_receipt_item_id);

        $this->order($size, 2);
        $this->assertSame($this->batch('DATED')->id, StockReservation::where('status', 'active')->value('inventory_batch_id'));
    }

    public function test_an_expiry_date_can_be_required_on_receipt(): void
    {
        config(['pharmacy.require_expiry_on_receipt' => true]);
        $size = $this->makeSize(price: 50, stock: 0);
        $purchasing = app(PurchasingService::class);
        $po = $purchasing->createOrder($this->supplier, Warehouse::default()->id, [['product_size_id' => $size->id, 'quantity' => 3, 'unit_cost' => 10]], [], $this->admin->id);
        $purchasing->markOrdered($po, $this->admin->id);

        $this->expectExceptionMessage('expiry date');
        $purchasing->receive($po->fresh(), [$po->items->first()->id => ['received' => 3, 'lot_number' => 'L1']], null, null, $this->admin->id);
    }
}
