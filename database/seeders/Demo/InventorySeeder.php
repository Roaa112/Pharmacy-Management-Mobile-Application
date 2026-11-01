<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\ProductSize;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PurchasingService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\TransferService;
use Illuminate\Database\Seeder;

/**
 * Warehouses, suppliers and the inventory workflows, all through the same services the
 * dashboard uses: a partially received purchase order (with damaged units), a supplier invoice
 * and payment, a supplier return, a transfer to the branch, a completed count, and a quarantine
 * inspection of the returned sunscreen.
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $adminId = Admin::value('id');
        $main = Warehouse::default();
        $branch = Warehouse::firstOrCreate(['code' => 'BRANCH'], ['name' => 'City branch', 'country_id' => $main->country_id, 'is_sellable' => true, 'is_active' => true]);
        $main->locations()->firstOrCreate(['code' => 'A-01'], ['name' => 'Medicines, aisle A']);
        $main->locations()->firstOrCreate(['code' => 'B-01'], ['name' => 'Baby care, aisle B']);

        $sizes = ProductSize::whereHas('product')->orderBy('id')->take(6)->get();
        foreach ($sizes as $i => $size) {
            $size->update([
                'sku' => $size->sku ?? sprintf('SKU-%05d', $size->id),
                'barcode' => $size->barcode ?? sprintf('62200000%05d', $size->id),
                'cost_price' => $size->cost_price ?? round($size->price * 0.6, 2),
                'reorder_point' => 5, 'reorder_quantity' => 20, 'min_stock' => 2, 'max_stock' => 200,
            ]);
        }

        $purchasing = app(PurchasingService::class);
        $supplier = Supplier::firstOrCreate(['code' => 'PHARMA-DIST'], [
            'name' => 'National Pharma Distribution', 'contact_name' => 'Sales desk', 'phone' => '0200000000',
            'email' => 'orders@pharma-dist.example', 'currency_code' => $main->country?->currency_code, 'payment_terms_days' => 30,
        ]);
        Supplier::firstOrCreate(['code' => 'BABY-CARE'], ['name' => 'Baby Care Supplies', 'phone' => '0200000001', 'payment_terms_days' => 15]);

        // Purchase order: received partly (one damaged unit); the rest is still outstanding.
        $po = $purchasing->createOrder($supplier, $main->id, [
            ['product_size_id' => $sizes[0]->id, 'quantity' => 40, 'unit_cost' => $sizes[0]->cost_price],
            ['product_size_id' => $sizes[1]->id, 'quantity' => 24, 'unit_cost' => $sizes[1]->cost_price],
        ], ['expected_at' => now()->addDays(3)->toDateString(), 'notes' => 'Monthly replenishment'], $adminId);
        $purchasing->markOrdered($po, $adminId);
        $items = $po->items()->orderBy('id')->get();
        $purchasing->receive($po, [$items[0]->id => ['received' => 30, 'damaged' => 1], $items[1]->id => ['received' => 24]], 'INV-1001', 'First delivery', $adminId);

        $invoice = $supplier->invoices()->create(['purchase_order_id' => $po->id, 'number' => 'INV-1001', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => $po->fresh()->total, 'created_by' => $adminId]);
        $supplier->payments()->create(['supplier_invoice_id' => $invoice->id, 'amount' => round($invoice->amount / 2, 2), 'paid_on' => now()->toDateString(), 'method' => 'bank transfer', 'reference' => 'TRX-55', 'created_by' => $adminId]);

        // A draft purchase order (no stock change).
        $purchasing->createOrder($supplier, $main->id, [['product_size_id' => $sizes[2]->id, 'quantity' => 12, 'unit_cost' => $sizes[2]->cost_price]], [], $adminId);

        // The damaged unit goes back to the supplier.
        $return = $purchasing->createReturn($supplier, $main->id, [['product_size_id' => $sizes[0]->id, 'quantity' => 1, 'source_bucket' => 'damaged']], 'Broken on arrival', $po->id, $adminId);
        $purchasing->dispatchReturn($return, $adminId);

        // Transfer to the branch: 10 dispatched, 8 received, closed with 2 lost.
        $transfers = app(TransferService::class);
        $transfer = $transfers->request($main->id, $branch->id, [$sizes[1]->id => 10], 'Stock for the new branch', $adminId);
        $transfers->approve($transfer, $adminId);
        $transfers->dispatch($transfer, $adminId);
        $transfers->receive($transfer, [$transfer->items->first()->id => 8], 'Two boxes missing', $adminId);
        $transfers->close($transfer, 'Two boxes lost by the carrier', $adminId);

        // Counted shelf: one unit fewer than the system expected.
        $counts = app(StockCountService::class);
        $count = $counts->start($main->id, 'Quarterly count, aisle A', $adminId);
        $balance = $sizes[3]->balances()->where('warehouse_id', $main->id)->first();
        $counts->record($count, [$sizes[3]->id => max((int) $balance?->reserved, (int) $balance?->on_hand - 1)]);
        $counts->complete($count, $adminId);

        // A unit found damaged on the shelf.
        app(InventoryService::class)->markDamaged($sizes[4]->id, $main->id, 1, 'Packaging torn', $adminId);

        // Returned units inspected: back on sale.
        $quarantined = ProductSize::where('quarantined_stock', '>', 0)->first();
        if ($quarantined) {
            $warehouseId = $quarantined->balances()->where('quarantined', '>', 0)->value('warehouse_id');
            app(InventoryService::class)->inspectQuarantine($quarantined->id, $warehouseId, 1, 0, 0, 'Sealed, undamaged: back on sale', $adminId);
        }
    }
}
