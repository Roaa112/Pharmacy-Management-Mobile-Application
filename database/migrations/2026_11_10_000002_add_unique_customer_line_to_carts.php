<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer line per size in a cart. A customer line is identified by (user, size); gift lines
 * are identified by (user, gift_key) (already unique) and may share a size with each other or with
 * a customer line, so the key is a generated column: the size for customer lines, NULL for gifts
 * (NULLs never collide). A double-tapped "add to cart" now merges instead of adding a second line.
 *
 * Existing duplicates are not merged here (they are customer data): the migration stops and
 * says which rows to merge first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('carts')->where('is_gift', false)->whereNotNull('product_size_id')
            ->select('user_id', 'product_size_id')->groupBy('user_id', 'product_size_id')->havingRaw('COUNT(*) > 1')->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'carts has %d (user_id, product_size_id) pairs with more than one customer line (is_gift = 0). '
                .'Merge each pair into one line (sum product_quantity, keep the lowest id) before running this migration. First: %s',
                $duplicates->count(), $duplicates->take(5)->map(fn ($d) => "user {$d->user_id} / size {$d->product_size_id}")->implode(', ')
            ));
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_line_size_id')->nullable()->virtualAs('case when is_gift = 0 then product_size_id end');
            $table->unique(['user_id', 'customer_line_size_id'], 'carts_one_customer_line_per_size');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_one_customer_line_per_size');
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('customer_line_size_id');
        });
    }
};
