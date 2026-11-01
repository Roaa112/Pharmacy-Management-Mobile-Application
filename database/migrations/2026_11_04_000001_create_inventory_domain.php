<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory domain: warehouses, per-warehouse balances, order reservations, purchasing,
 * supplier accounts, transfers and stock counts.
 *
 * product_sizes stays the variant (SKU) entity; it gains SKU, barcode, colour, cost and
 * replenishment levels. product_sizes.stock / quarantined_stock become projections of
 * inventory_balances (sum over sellable warehouses), written only by InventoryService in the
 * same transaction as the balance; `php artisan inventory:verify` checks they agree.
 *
 * Backfill (no quantity is invented or lost):
 *  - one default warehouse "MAIN";
 *  - for each size: on_hand = stock + units of open (pending/confirmed) orders, reserved = those
 *    units, quarantined = quarantined_stock. Open legacy orders already took their units off
 *    product_sizes.stock at checkout but the goods are still on the shelf, so they become
 *    active reservations; available (on_hand - reserved) equals the old stock exactly;
 *  - one "opening_balance" ledger row per non-zero bucket so the ledger sums to the balances;
 *  - order_items.stock_state from the order status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            // Sellable warehouses feed the online stock (product_sizes.stock).
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::table('product_sizes', function (Blueprint $table) {
            $table->string('sku', 64)->nullable()->unique()->after('size');
            $table->string('barcode', 64)->nullable()->unique()->after('sku');
            $table->string('color', 50)->nullable()->after('barcode');
            $table->decimal('cost_price', 12, 2)->nullable()->after('price');
            $table->unsignedInteger('min_stock')->nullable();
            $table->unsignedInteger('max_stock')->nullable();
            $table->unsignedInteger('reorder_point')->nullable();
            $table->unsignedInteger('reorder_quantity')->nullable();
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('on_hand')->default(0);      // physically in the warehouse, sellable condition
            $table->unsignedInteger('reserved')->default(0);     // part of on_hand held for orders
            $table->unsignedInteger('damaged')->default(0);      // not sellable, awaiting write-off / supplier return
            $table->unsignedInteger('quarantined')->default(0);  // returned by customers, awaiting inspection
            $table->unsignedInteger('in_transit')->default(0);   // dispatched to this warehouse, not received yet
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_size_id']);
            $table->index('product_size_id');
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('active'); // active | fulfilled | released
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'product_size_id']);
            $table->unique(['order_item_id', 'warehouse_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // reserved | fulfilled | released | restocked | returned ; null = legacy line (stock taken at checkout)
            $table->string('stock_state', 20)->nullable()->after('discount_rule_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_size_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity_after');
            $table->text('notes')->nullable()->after('reason');
            // Every business event that moves stock has one key per bucket it touches, so a
            // repeated request can never move the same units twice.
            $table->string('idempotency_key', 191)->nullable()->after('reference_id');
        });

        DB::table('stock_movements')->update(['idempotency_key' => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? DB::raw("CONCAT('legacy:', id)")
            : DB::raw("'legacy:' || id")]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_once_per_reference');
            $table->unique('idempotency_key');
            $table->index(['warehouse_id', 'product_size_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'created_at']);
        });

        $this->createPurchasing();
        $this->createTransfersAndCounts();
        $this->backfill();
    }

    private function createPurchasing(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // draft | ordered | partially_received | received | closed | canceled
            $table->string('status', 20)->default('draft');
            $table->char('currency_code', 3)->nullable();
            $table->date('order_date')->nullable();
            $table->date('expected_at')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expected_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->unique(['purchase_order_id', 'product_size_id']);
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 64);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency_code', 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id', 'number']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 30)->nullable();
            $table->string('reference', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft | dispatched | canceled
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_bucket', 20)->default('damaged'); // on_hand | damaged | quarantined
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    private function createTransfersAndCounts(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // requested | approved | in_transit | partially_received | received | closed | canceled
            $table->string('status', 20)->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_dispatched')->default(0);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_lost')->default(0); // discrepancy written off at closing
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'product_size_id']);
        });

        Schema::create('stock_transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('quantities'); // stock_transfer_item_id => units received
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft | completed | canceled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('counted_quantity');
            $table->unsignedInteger('expected_quantity')->nullable(); // on_hand at completion
            $table->integer('difference')->nullable();
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_size_id']);
        });
    }

    private function backfill(): void
    {
        $now = now();
        $countryId = DB::table('countries')->where('is_default', true)->value('id');

        $warehouseId = DB::table('warehouses')->insertGetId([
            'country_id' => $countryId, 'code' => 'MAIN', 'name' => 'Main warehouse',
            'is_sellable' => true, 'is_active' => true, 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Line states from the order status.
        $openOrders = DB::table('orders')->whereIn('status', ['pending', 'confirmed'])->pluck('id');
        DB::table('order_items')->whereIn('order_id', $openOrders)->update(['stock_state' => 'reserved']);
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->whereIn('status', ['on_delivery', 'completed'])->select('id'))
            ->update(['stock_state' => 'fulfilled']);
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('status', 'canceled')->select('id'))
            ->update(['stock_state' => 'restocked']);

        DB::table('product_sizes')->orderBy('id')->chunkById(500, function ($sizes) use ($warehouseId, $openOrders, $now) {
            $openUnits = DB::table('order_items')->whereIn('order_id', $openOrders)->whereIn('product_size_id', $sizes->pluck('id'))
                ->groupBy('product_size_id')->selectRaw('product_size_id, SUM(quantity) as units')->pluck('units', 'product_size_id');

            foreach ($sizes as $size) {
                $reserved = (int) ($openUnits[$size->id] ?? 0);
                $available = max(0, (int) $size->stock);
                $buckets = ['on_hand' => $available + $reserved, 'reserved' => $reserved, 'quarantined' => (int) $size->quarantined_stock];

                DB::table('inventory_balances')->insert([
                    'warehouse_id' => $warehouseId, 'product_size_id' => $size->id,
                    'on_hand' => $buckets['on_hand'], 'reserved' => $reserved, 'quarantined' => $buckets['quarantined'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ($buckets as $bucket => $quantity) {
                    if ($quantity > 0) {
                        DB::table('stock_movements')->insert([
                            'product_id' => $size->product_id, 'product_size_id' => $size->id, 'warehouse_id' => $warehouseId,
                            'type' => 'opening_balance', 'bucket' => $bucket,
                            'quantity_before' => 0, 'quantity_change' => $quantity, 'quantity_after' => $quantity,
                            'idempotency_key' => "opening:{$size->id}:{$warehouseId}:{$bucket}",
                            'reason' => 'Balance at warehouse introduction', 'created_at' => $now,
                        ]);
                    }
                }
            }
        });

        // Active reservations for the open legacy orders.
        DB::table('order_items')->whereIn('order_id', $openOrders)->whereNotNull('product_size_id')->orderBy('id')
            ->chunkById(500, function ($items) use ($warehouseId, $now) {
                DB::table('stock_reservations')->insert($items->map(fn ($item) => [
                    'order_item_id' => $item->id, 'product_size_id' => $item->product_size_id, 'warehouse_id' => $warehouseId,
                    'quantity' => $item->quantity, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_receipts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');

        // Ledger rows written by the new domain reference warehouses; they are history of the
        // new model and are removed with it. Legacy rows (warehouse_id null) are kept.
        DB::table('stock_movements')->whereNotNull('warehouse_id')->delete();
        Schema::table('stock_movements', function (Blueprint $table) {
            // MySQL: the foreign key first, it relies on the composite index.
            $table->dropForeign(['warehouse_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'product_size_id', 'created_at']);
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropIndex(['type', 'created_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['warehouse_id', 'unit_cost', 'notes', 'idempotency_key']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unique(['type', 'reference_type', 'reference_id'], 'stock_movements_once_per_reference');
        });

        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('stock_state'));
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('inventory_balances');

        Schema::table('product_sizes', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropUnique(['barcode']);
            $table->dropColumn(['sku', 'barcode', 'color', 'cost_price', 'min_stock', 'max_stock', 'reorder_point', 'reorder_quantity']);
        });

        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
    }
};
