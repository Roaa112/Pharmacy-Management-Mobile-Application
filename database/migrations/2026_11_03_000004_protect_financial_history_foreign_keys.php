<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, order lines, return requests and point reversals are financial history. Deleting a
 * customer, product or size used to cascade and erase them; now the delete is refused and the
 * customer must be deactivated instead. Only the ON DELETE rule changes: no rows are touched,
 * and the existing constraints guarantee there are no orphans to clean up first.
 */
return new class extends Migration
{
    private array $keys = [
        'orders' => ['user_id' => 'users'],
        'order_items' => ['order_id' => 'orders', 'product_id' => 'products', 'product_size_id' => 'product_sizes'],
        'return_requests' => ['user_id' => 'users', 'order_id' => 'orders'],
        'loyalty_point_reversals' => ['order_id' => 'orders'],
    ];

    public function up(): void
    {
        $this->rebuild('restrict');
    }

    public function down(): void
    {
        $this->rebuild('cascade');
    }

    private function rebuild(string $onDelete): void
    {
        foreach ($this->keys as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $onDelete) {
                foreach ($columns as $column => $references) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
                }
            });
        }
    }
};
