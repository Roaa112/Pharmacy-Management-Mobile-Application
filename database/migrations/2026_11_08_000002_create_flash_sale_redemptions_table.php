<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flash-sale units sold, one row per order line priced by the sale (like promotion_redemptions):
 * reserved at checkout, consumed on completion, released on cancellation. Checkout counts the
 * sale's limits from these rows with a locking read, which always sees committed rows (counting
 * order_items joined to orders could not be locked without locking other customers' orders).
 *
 * Backfill: every existing flash-priced line, released when its order was canceled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_redemptions', function (Blueprint $table) {
            $table->id();
            // A deleted sale has no limits left to count; the order lines keep their prices.
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_size_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('status', 20); // reserved | consumed | released
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['flash_sale_id', 'status']);
            $table->index(['flash_sale_id', 'user_id']);
        });

        DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.flash_sale_id')->where('order_items.is_gift', false)
            ->whereIn('order_items.flash_sale_id', DB::table('flash_sales')->select('id'))
            ->orderBy('order_items.id')
            ->select('order_items.id', 'order_items.order_id', 'order_items.flash_sale_id', 'order_items.product_id', 'order_items.product_size_id',
                'order_items.quantity', 'orders.user_id', 'orders.status', 'orders.created_at')
            ->chunk(500, function ($lines) {
                DB::table('flash_sale_redemptions')->insert($lines->map(fn ($line) => [
                    'flash_sale_id' => $line->flash_sale_id, 'order_id' => $line->order_id, 'order_item_id' => $line->id,
                    'user_id' => $line->user_id, 'product_id' => $line->product_id, 'product_size_id' => $line->product_size_id,
                    'quantity' => $line->quantity,
                    'status' => match ($line->status) { 'canceled' => 'released', 'completed' => 'consumed', default => 'reserved' },
                    'created_at' => $line->created_at ?? now(), 'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_redemptions');
    }
};
