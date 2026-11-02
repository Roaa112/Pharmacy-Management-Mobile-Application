<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch (lot) and expiry tracking under the existing per-warehouse balances.
 *
 *  - inventory_batches: one lot of one SKU (lot number, expiry date, supplier, receipt line).
 *    A "legacy" batch per SKU holds stock that existed before batches (unknown lot and expiry).
 *  - batch_balances: the same buckets as inventory_balances, per warehouse and batch. Invariant:
 *    for every warehouse, SKU and bucket, the batch balances add up to the inventory balance.
 *  - stock_movement_batches: which batches each ledger movement changed (signed quantities).
 *  - stock_reservations.inventory_batch_id: the batch a reservation holds (FEFO at checkout).
 *
 * Backward compatible: inventory_balances, product_sizes.stock and stock_movements keep their
 * meaning. Backfill: each SKU's current balances go to its legacy batch, active reservations point
 * at it, so the invariant holds immediately and existing stock stays sellable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_size_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['product_size_id', 'expiry_date']);
            $table->index(['product_size_id', 'lot_number']);
            $table->index('expiry_date');
        });

        Schema::create('batch_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_size_id'); // denormalised for per-SKU locking reads
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('damaged')->default(0);
            $table->integer('quarantined')->default(0);
            $table->integer('in_transit')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'inventory_batch_id']);
            $table->index(['product_size_id', 'warehouse_id']);
        });

        Schema::create('stock_movement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_change');
            $table->index('inventory_batch_id');
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete();
        });
        Schema::table('stock_reservations', function (Blueprint $table) {
            // The order_item_id foreign key may rely on the old unique index: give it its own first.
            $table->index('order_item_id', 'stock_reservations_order_item_fk_index');
        });
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropUnique(['order_item_id', 'warehouse_id']);
            $table->unique(['order_item_id', 'warehouse_id', 'inventory_batch_id'], 'stock_reservations_line_batch_unique');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();

        DB::table('inventory_balances')->select('product_size_id')->distinct()->orderBy('product_size_id')
            ->pluck('product_size_id')->chunk(500)->each(function ($sizeIds) use ($now) {
                foreach ($sizeIds as $sizeId) {
                    $batchId = DB::table('inventory_batches')->insertGetId([
                        'product_size_id' => $sizeId, 'is_legacy' => true, 'notes' => 'Stock recorded before batch tracking',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);

                    foreach (DB::table('inventory_balances')->where('product_size_id', $sizeId)->get() as $balance) {
                        DB::table('batch_balances')->insert([
                            'warehouse_id' => $balance->warehouse_id, 'inventory_batch_id' => $batchId, 'product_size_id' => $sizeId,
                            'on_hand' => $balance->on_hand, 'reserved' => $balance->reserved, 'damaged' => $balance->damaged,
                            'quarantined' => $balance->quarantined, 'in_transit' => $balance->in_transit,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }

                    DB::table('stock_reservations')->where('product_size_id', $sizeId)->update(['inventory_batch_id' => $batchId]);
                }
            });
    }

    public function down(): void
    {
        // Only safe while every SKU still has a single batch per warehouse (otherwise reservations of
        // one line in two batches of one warehouse would break the old unique key): merge first.
        $split = DB::table('stock_reservations')->select('order_item_id', 'warehouse_id')
            ->groupBy('order_item_id', 'warehouse_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($split) {
            throw new RuntimeException('Some order lines hold reservations in several batches of one warehouse; merge them before rolling back.');
        }

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropUnique('stock_reservations_line_batch_unique');
            $table->unique(['order_item_id', 'warehouse_id']);
        });
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropIndex('stock_reservations_order_item_fk_index');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });

        Schema::dropIfExists('stock_movement_batches');
        Schema::dropIfExists('batch_balances');
        Schema::dropIfExists('inventory_batches');
    }
};
