<?php

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InsufficientStock;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PurchasingService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\TransferService;
use App\Modules\Orders\Services\OrderWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class InventoryDomainTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->main = Warehouse::default();
    }

    /** Every test must leave balances, ledger, reservations and catalogue stock consistent. */
    protected function assertPostConditions(): void
    {
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }

    private function balance(ProductSize $size, ?Warehouse $warehouse = null): InventoryBalance
    {
        return InventoryBalance::firstOrNew(['warehouse_id' => ($warehouse ?? $this->main)->id, 'product_size_id' => $size->id]);
    }

    private function buckets(ProductSize $size, ?Warehouse $warehouse = null): array
    {
        $b = $this->balance($size, $warehouse)->refresh();

        return [(int) $b->on_hand, (int) $b->reserved, (int) $b->damaged, (int) $b->quarantined, (int) $b->in_transit];
    }

    private function branch(bool $sellable = true): Warehouse
    {
        return Warehouse::create(['code' => 'BR', 'name' => 'Branch', 'is_sellable' => $sellable, 'is_active' => true]);
    }

    private function checkout(User $user, ProductSize $size, int $quantity): Order
    {
        Sanctum::actingAs($user);
        $this->putInCart($user, $size, $quantity);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    // ------------------------------------------------------------------ purchasing

    public function test_a_purchase_order_changes_stock_only_when_goods_are_received(): void
    {
        $size = $this->makeSize(price: 20, stock: 0);
        $purchasing = app(PurchasingService::class);
        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Supplier']);

        $po = $purchasing->createOrder($supplier, $this->main->id, [['product_size_id' => $size->id, 'quantity' => 10, 'unit_cost' => '8.00']], ['shipping_cost' => 5], $this->admin->id);
        $this->assertSame('85.00', number_format((float) $po->total, 2, '.', ''));
        $purchasing->markOrdered($po, $this->admin->id);
        $this->assertSame([0, 0, 0, 0, 0], $this->buckets($size), 'ordering is not receiving');

        // Partial receipt with one damaged unit.
        $item = $po->items()->sole();
        $purchasing->receive($po, [$item->id => ['received' => 6, 'damaged' => 1]], 'INV-1', null, $this->admin->id);
        $this->assertSame([6, 0, 1, 0, 0], $this->buckets($size));
        $this->assertSame(6, $size->fresh()->stock);
        $this->assertSame([PurchaseOrder::PARTIALLY_RECEIVED, 3], [$po->fresh()->status, $item->fresh()->outstanding]);
        $this->assertEquals(8, $size->fresh()->cost_price);

        try {
            $purchasing->receive($po, [$item->id => ['received' => 4]], null, null, $this->admin->id);
            $this->fail('Receiving more than outstanding must be refused.');
        } catch (DomainException) {
        }

        $purchasing->receive($po, [$item->id => ['received' => 3]], null, null, $this->admin->id);
        $this->assertSame(PurchaseOrder::RECEIVED, $po->fresh()->status);
        $this->assertSame(9, $size->fresh()->stock);
        $this->assertSame(2, $po->receipts()->count());
        $this->assertSame(3, StockMovement::where('type', StockMovement::TYPE_PURCHASE_RECEIPT)->count(), 'on_hand + damaged, then on_hand');
    }

    public function test_receipts_update_the_weighted_average_cost(): void
    {
        $size = $this->makeSize(stock: 0);
        $size->update(['cost_price' => 10]);
        app(InventoryService::class)->adjust($size->id, $this->main->id, 10, 'Opening count', $this->admin->id);

        $purchasing = app(PurchasingService::class);
        $po = $purchasing->createOrder(Supplier::create(['code' => 'S', 'name' => 'S']), $this->main->id, [['product_size_id' => $size->id, 'quantity' => 10, 'unit_cost' => 20]], [], $this->admin->id);
        $purchasing->markOrdered($po, $this->admin->id);
        $purchasing->receive($po, [$po->items()->value('id') => ['received' => 10]], null, null, $this->admin->id);

        $this->assertEquals(15, $size->fresh()->cost_price);
    }

    public function test_draft_and_canceled_purchase_orders_cannot_be_received(): void
    {
        $size = $this->makeSize(stock: 0);
        $purchasing = app(PurchasingService::class);
        $po = $purchasing->createOrder(Supplier::create(['code' => 'S', 'name' => 'S']), $this->main->id, [['product_size_id' => $size->id, 'quantity' => 1, 'unit_cost' => 1]], [], $this->admin->id);

        $this->expectException(DomainException::class);
        $purchasing->receive($po, [$po->items()->value('id') => ['received' => 1]], null, null, $this->admin->id);
    }

    public function test_supplier_returns_take_damaged_units_and_credit_the_supplier(): void
    {
        $size = $this->makeSize(stock: 5);
        $size->update(['cost_price' => 4]);
        $supplier = Supplier::create(['code' => 'S', 'name' => 'S']);
        $supplier->invoices()->create(['number' => 'I1', 'invoice_date' => now(), 'amount' => 100]);
        app(InventoryService::class)->markDamaged($size->id, $this->main->id, 2, 'Broken', $this->admin->id);

        $purchasing = app(PurchasingService::class);
        $return = $purchasing->createReturn($supplier, $this->main->id, [['product_size_id' => $size->id, 'quantity' => 2, 'source_bucket' => 'damaged']], 'Broken', null, $this->admin->id);
        $this->assertSame([3, 0, 2, 0, 0], $this->buckets($size), 'a draft return moves nothing');

        $purchasing->dispatchReturn($return, $this->admin->id);
        $this->assertSame([3, 0, 0, 0, 0], $this->buckets($size));
        $this->assertSame(9200, $supplier->balanceMinor());

        $this->expectException(DomainException::class);
        $purchasing->dispatchReturn($return, $this->admin->id);
    }

    // ------------------------------------------------------------------ transfers

    public function test_transfers_conserve_units_through_dispatch_partial_receipt_and_loss(): void
    {
        $size = $this->makeSize(stock: 10);
        $branch = $this->branch();
        $transfers = app(TransferService::class);

        $transfer = $transfers->request($this->main->id, $branch->id, [$size->id => 6], null, $this->admin->id);
        $this->assertSame([10, 0, 0, 0, 0], $this->buckets($size), 'requesting moves nothing');

        try {
            $transfers->dispatch($transfer, $this->admin->id);
            $this->fail('An unapproved transfer cannot be dispatched.');
        } catch (DomainException) {
        }

        $transfers->approve($transfer, $this->admin->id);
        $transfers->dispatch($transfer, $this->admin->id);
        $this->assertSame([4, 0, 0, 0, 0], $this->buckets($size));
        $this->assertSame([0, 0, 0, 0, 6], $this->buckets($size, $branch));
        $this->assertSame(4, $size->fresh()->stock, 'in-transit units are not sellable');

        $item = $transfer->items()->sole();
        $transfers->receive($transfer, [$item->id => 4], null, $this->admin->id);
        $this->assertSame(StockTransfer::PARTIALLY_RECEIVED, $transfer->fresh()->status);
        $this->assertSame([4, 0, 0, 0, 2], $this->buckets($size, $branch));

        try {
            $transfers->receive($transfer, [$item->id => 3], null, $this->admin->id);
            $this->fail('Cannot receive more than is in transit.');
        } catch (DomainException) {
        }
        try {
            $transfers->close($transfer, null, $this->admin->id);
            $this->fail('Closing with units missing needs a reason.');
        } catch (DomainException) {
        }

        $transfers->close($transfer, 'Lost by carrier', $this->admin->id);
        $this->assertSame([4, 0, 0, 0, 0], $this->buckets($size, $branch));
        $this->assertSame(2, $item->fresh()->quantity_lost);
        $this->assertSame(8, $size->fresh()->stock);
        $this->assertSame(10, 4 + 4 + $item->fresh()->quantity_lost, 'source + destination + lost = original');
    }

    public function test_a_transfer_cannot_send_reserved_or_missing_units(): void
    {
        $size = $this->makeSize(stock: 3);
        $this->checkout(User::factory()->create(), $size, 2);
        $transfers = app(TransferService::class);
        $transfer = $transfers->request($this->main->id, $this->branch()->id, [$size->id => 2], null, $this->admin->id);
        $transfers->approve($transfer, $this->admin->id);

        try {
            $transfers->dispatch($transfer, $this->admin->id);
            $this->fail('Only one unit is available; two are reserved for an order.');
        } catch (InsufficientStock) {
        }

        $this->assertSame([3, 2, 0, 0, 0], $this->buckets($size));
        $this->assertSame(StockTransfer::APPROVED, $transfer->fresh()->status);
    }

    // ------------------------------------------------------------------ orders and warehouses

    public function test_reservations_are_split_across_sellable_warehouses_and_skip_non_sellable_ones(): void
    {
        $size = $this->makeSize(stock: 2);
        $branch = $this->branch();
        $storage = Warehouse::create(['code' => 'STORE', 'name' => 'Back store', 'is_sellable' => false, 'is_active' => true]);
        $inventory = app(InventoryService::class);
        $inventory->adjust($size->id, $branch->id, 3, 'Opening', $this->admin->id);
        $inventory->adjust($size->id, $storage->id, 50, 'Opening', $this->admin->id);
        $this->assertSame(5, $size->fresh()->stock, 'non-sellable stock is not offered online');

        $order = $this->checkout(User::factory()->create(), $size, 4);
        $reservations = StockReservation::orderBy('id')->get(['warehouse_id', 'quantity'])->map(fn ($r) => [$r->warehouse_id, $r->quantity])->all();
        $this->assertSame([[$this->main->id, 2], [$branch->id, 2]], $reservations, 'default warehouse first');
        $this->assertSame(1, $size->fresh()->stock);

        app(OrderWorkflow::class)->transition($order, OrderStatus::OnDelivery, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame([0, 0, 0, 0, 0], $this->buckets($size));
        $this->assertSame([1, 0, 0, 0, 0], $this->buckets($size, $branch));
        $this->assertSame([50, 0, 0, 0, 0], $this->buckets($size, $storage));
        $this->assertSame(OrderItem::STOCK_FULFILLED, $order->items()->value('stock_state'));
    }

    public function test_cancellation_releases_reservations_or_returns_dispatched_units_to_quarantine_once(): void
    {
        $size = $this->makeSize(stock: 5);
        $workflow = app(OrderWorkflow::class);

        $open = $this->checkout(User::factory()->create(), $size, 2);
        $workflow->transition($open, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame([5, 0, 0, 0, 0], $this->buckets($size));
        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RESERVATION_RELEASE)->count());

        $dispatched = $this->checkout(User::factory()->create(), $size, 3);
        $workflow->transition($dispatched, OrderStatus::OnDelivery, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $workflow->transition($dispatched, OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame([2, 0, 0, 3, 0], $this->buckets($size), 'dispatched units come back for inspection');

        // Re-running the stock side of the cancellation does nothing (idempotent per line).
        app(InventoryService::class)->releaseForCancellation($dispatched->items()->first());
        $this->assertSame([2, 0, 0, 3, 0], $this->buckets($size));
    }

    public function test_concurrent_style_overselling_is_refused(): void
    {
        $size = $this->makeSize(stock: 1);
        $this->checkout(User::factory()->create(), $size, 1);

        // A second order for the last unit is refused at checkout and changes nothing.
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->putInCart($user, $size, 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertStatus(422);

        // Even bypassing checkout, a reservation beyond availability fails atomically.
        $item = OrderItem::create(['order_id' => Order::first()->id, 'product_id' => $size->product_id, 'product_size_id' => $size->id, 'quantity' => 1, 'price_at_time' => 1, 'original_price' => 1, 'total_price' => 1]);
        try {
            app(InventoryService::class)->reserve($item);
            $this->fail('Overselling must be refused.');
        } catch (InsufficientStock) {
        }
        $this->assertSame([1, 1, 0, 0, 0], $this->buckets($size));
        $this->assertSame(1, StockReservation::count());
        $item->forceFill(['stock_state' => OrderItem::STOCK_RELEASED])->save();
    }

    public function test_a_gift_line_is_reserved_and_leaves_as_a_gift_movement(): void
    {
        $size = $this->makeSize(stock: 4);
        $order = $this->checkout(User::factory()->create(), $size, 1);
        $giftSize = $this->makeSize(price: 30, stock: 2);
        $gift = OrderItem::create(['order_id' => $order->id, 'product_id' => $giftSize->product_id, 'product_size_id' => $giftSize->id, 'quantity' => 1,
            'price_at_time' => 0, 'original_price' => 30, 'total_price' => 0, 'is_gift' => true]);
        app(InventoryService::class)->reserve($gift, ['reason' => 'Gift']);

        app(OrderWorkflow::class)->transition($order, OrderStatus::OnDelivery, OrderWorkflow::ACTOR_ADMIN, $this->admin->id);
        $this->assertSame(2, StockMovement::where('type', StockMovement::TYPE_GIFT)->where('product_size_id', $giftSize->id)->count());
        $this->assertSame([1, 0, 0, 0, 0], $this->buckets($giftSize));
    }

    // ------------------------------------------------------------------ warehouse operations

    public function test_damage_write_off_and_inspection_are_the_only_ways_returned_units_become_sellable(): void
    {
        $size = $this->makeSize(stock: 10);
        $inventory = app(InventoryService::class);
        $inventory->apply($size->id, $this->main->id, ['quarantined' => 4], StockMovement::TYPE_RETURN_QUARANTINE, ['reason' => 'Customer return'], 'test-return');
        $this->assertSame(10, $size->fresh()->stock, 'returned units are not on sale');

        $inventory->inspectQuarantine($size->id, $this->main->id, 2, 1, 1, 'Inspected', $this->admin->id);
        $this->assertSame([12, 0, 1, 0, 0], $this->buckets($size));

        $inventory->markDamaged($size->id, $this->main->id, 2, 'Dropped', $this->admin->id);
        $inventory->writeOffDamaged($size->id, $this->main->id, 3, 'Destroyed', $this->admin->id);
        $this->assertSame([10, 0, 0, 0, 0], $this->buckets($size));

        $this->expectException(DomainException::class);
        $inventory->adjust($size->id, $this->main->id, -1, '   ', $this->admin->id);
    }

    public function test_stock_counts_record_differences_and_refuse_counts_below_reservations(): void
    {
        $size = $this->makeSize(stock: 10);
        $other = $this->makeSize(stock: 5);
        $this->checkout(User::factory()->create(), $other, 3);
        $counts = app(StockCountService::class);

        $count = $counts->start($this->main->id, null, $this->admin->id);
        $counts->record($count, [$size->id => 8, $other->id => '']);
        // A sale dispatched while counting: the count compares with on_hand at completion.
        $counts->complete($count, $this->admin->id);
        $item = $count->items()->sole();
        $this->assertSame([10, -2], [$item->expected_quantity, $item->difference]);
        $this->assertSame([8, 0, 0, 0, 0], $this->buckets($size));

        $bad = $counts->start($this->main->id, null, $this->admin->id);
        $counts->record($bad, [$other->id => 2]);
        try {
            $counts->complete($bad, $this->admin->id);
            $this->fail('Counting fewer units than are reserved must be refused.');
        } catch (DomainException) {
        }
        $this->assertSame([5, 3, 0, 0, 0], $this->buckets($other));
    }

    public function test_ledger_rows_are_idempotent_and_never_negative(): void
    {
        $size = $this->makeSize(stock: 2);
        $inventory = app(InventoryService::class);

        $first = $inventory->apply($size->id, $this->main->id, ['on_hand' => 5], StockMovement::TYPE_ADJUSTMENT, ['reason' => 'x'], 'same-event');
        $second = $inventory->apply($size->id, $this->main->id, ['on_hand' => 5], StockMovement::TYPE_ADJUSTMENT, ['reason' => 'x'], 'same-event');
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(7, $size->fresh()->stock);

        foreach ([['on_hand' => -8], ['damaged' => -1], ['reserved' => 8]] as $deltas) {
            try {
                $inventory->apply($size->id, $this->main->id, $deltas, StockMovement::TYPE_ADJUSTMENT, [], 'bad-'.key($deltas));
                $this->fail('No bucket may go negative and reserved may not exceed on hand.');
            } catch (InsufficientStock) {
            }
        }
        $this->assertSame([7, 0, 0, 0, 0], $this->buckets($size));
    }

    public function test_the_integrity_check_detects_a_balance_changed_outside_the_ledger(): void
    {
        $size = $this->makeSize(stock: 4);
        DB::table('inventory_balances')->where('product_size_id', $size->id)->update(['on_hand' => 9]);

        $this->assertNotEmpty(app(InventoryIntegrity::class)->problems());
        $this->artisan('inventory:verify')->assertFailed();

        DB::table('inventory_balances')->where('product_size_id', $size->id)->update(['on_hand' => 4]);
        $this->artisan('inventory:verify')->assertSuccessful();
    }

    // ------------------------------------------------------------------ dashboard

    public function test_stock_changes_need_the_inventory_permission_a_reason_and_are_audited(): void
    {
        $size = $this->makeSize(stock: 5);
        $editor = Admin::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret-password']);
        $editor->forceFill(['role' => 'content_editor'])->save();
        $keeper = Admin::create(['name' => 'Keeper', 'email' => 'keeper@example.com', 'password' => 'secret-password']);
        $keeper->forceFill(['role' => 'warehouse_manager'])->save();
        $payload = ['operation' => 'adjust', 'warehouse_id' => $this->main->id, 'quantity' => 3, 'reason' => 'Found in back room'];

        $this->actingAs($editor, 'admin')->post(route('dashboard.inventory.operate', $size), $payload)->assertForbidden();
        $this->actingAs($keeper, 'admin')->post(route('dashboard.inventory.operate', $size), ['reason' => ''] + $payload)->assertSessionHasErrors('reason');
        $this->actingAs($keeper, 'admin')->post(route('dashboard.inventory.operate', $size), $payload)->assertSessionHas('success');

        $this->assertSame(8, $size->fresh()->stock);
        $movement = StockMovement::where('type', StockMovement::TYPE_ADJUSTMENT)->sole();
        $this->assertSame([$keeper->id, 'Found in back room'], [$movement->admin_id, $movement->reason]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'inventory.adjust', 'admin_id' => $keeper->id, 'subject_id' => $size->id]);

        $this->actingAs($keeper, 'admin')->get(route('dashboard.suppliers.index'))->assertOk();
        $this->actingAs($editor, 'admin')->get(route('dashboard.purchase_orders.index'))->assertForbidden();
    }

    public function test_inventory_and_purchasing_pages_render_with_data(): void
    {
        $size = $this->makeSize(stock: 6);
        $size->update(['sku' => 'PARA-500', 'barcode' => '6221234567890', 'cost_price' => 3, 'reorder_point' => 10, 'color' => 'White']);
        $branch = $this->branch();
        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Pharma Distribution']);
        $purchasing = app(PurchasingService::class);
        $po = $purchasing->createOrder($supplier, $this->main->id, [['product_size_id' => $size->id, 'quantity' => 4, 'unit_cost' => 3]], [], $this->admin->id);
        $transfer = app(TransferService::class)->request($this->main->id, $branch->id, [$size->id => 1], null, $this->admin->id);
        $count = app(StockCountService::class)->start($this->main->id, null, $this->admin->id);

        $as = $this->actingAs($this->admin, 'admin');
        $as->get(route('dashboard.inventory.index'))->assertOk()->assertSee('PARA-500')->assertSee('BR');
        $as->get(route('dashboard.inventory.index', ['search' => '6221234567890']))->assertOk()->assertSee('PARA-500');
        $as->get(route('dashboard.inventory.index', ['stock' => 'low', 'warehouse' => $this->main->id, 'color' => 'White']))->assertOk()->assertSee('PARA-500');
        $as->get(route('dashboard.inventory.show', $size))->assertOk()->assertSee('Lifecycle totals')->assertSee('Opening / initial stock');
        $as->get(route('dashboard.inventory.movements', ['type' => 'initial', 'warehouse' => $this->main->id]))->assertOk()->assertSee('initial');
        $as->get(route('dashboard.inventory.reports'))->assertOk()->assertSee('Integrity check passed')->assertSee('To reorder');
        $as->get(route('dashboard.warehouses.index'))->assertOk()->assertSee('Main warehouse');
        $as->get(route('dashboard.transfers.index'))->assertOk()->assertSee($transfer->reference);
        $as->get(route('dashboard.transfers.show', $transfer))->assertOk()->assertSee('Approve');
        $as->get(route('dashboard.counts.index'))->assertOk()->assertSee($count->reference);
        $as->get(route('dashboard.counts.show', $count))->assertOk()->assertSee('counts['.$size->id.']', false);
        $as->get(route('dashboard.suppliers.index'))->assertOk()->assertSee('Pharma Distribution');
        $as->get(route('dashboard.suppliers.show', $supplier))->assertOk()->assertSee($po->reference);
        $as->get(route('dashboard.purchase_orders.index'))->assertOk()->assertSee($po->reference);
        $as->get(route('dashboard.purchase_orders.show', $po))->assertOk()->assertSee('Approve');

        // Full PO workflow through the dashboard routes.
        $as->post(route('dashboard.purchase_orders.order', $po))->assertSessionHas('success');
        $as->post(route('dashboard.purchase_orders.receive', $po), ['lines' => [$po->items()->value('id') => ['received' => 4]]])->assertSessionHas('success');
        $this->assertSame(10, $size->fresh()->stock);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'purchase_order.receive']);
    }

    public function test_creating_documents_through_the_dashboard(): void
    {
        $size = $this->makeSize(stock: 5);
        $branch = $this->branch();
        $as = $this->actingAs($this->admin, 'admin');

        $as->post(route('dashboard.suppliers.store'), ['code' => 'NEW', 'name' => 'New supplier', 'is_active' => 1])->assertRedirect();
        $supplier = Supplier::where('code', 'NEW')->sole();
        $as->post(route('dashboard.purchase_orders.store'), ['supplier_id' => $supplier->id, 'warehouse_id' => $this->main->id,
            'lines' => [['product_size_id' => $size->id, 'quantity' => 3, 'unit_cost' => '2.50']]])->assertRedirect();
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(5, $size->fresh()->stock);

        $as->post(route('dashboard.transfers.store'), ['source_warehouse_id' => $this->main->id, 'destination_warehouse_id' => $branch->id,
            'lines' => [['product_size_id' => $size->id, 'quantity' => 2]]])->assertRedirect();
        $transfer = StockTransfer::sole();
        $as->post(route('dashboard.transfers.approve', $transfer));
        $as->post(route('dashboard.transfers.dispatch', $transfer));
        $as->post(route('dashboard.transfers.receive', $transfer), ['quantities' => [$transfer->items()->value('id') => 2]])->assertSessionHas('success');
        $this->assertSame(StockTransfer::RECEIVED, $transfer->fresh()->status);
        $this->assertSame(5, $size->fresh()->stock, 'moved between sellable warehouses: total unchanged');

        $as->post(route('dashboard.warehouses.store'), ['code' => 'W3', 'name' => 'Third', 'is_active' => 1, 'is_sellable' => 1])->assertSessionHas('success');
        $as->put(route('dashboard.warehouses.update', $branch), ['code' => 'BR', 'name' => 'Branch'])->assertSessionHas('error'); // holds stock: cannot deactivate
        $as->put(route('dashboard.inventory.updateSku', $size), ['sku' => 'X-1', 'max_stock' => 3, 'min_stock' => 5])->assertSessionHasErrors('max_stock');
    }
}
