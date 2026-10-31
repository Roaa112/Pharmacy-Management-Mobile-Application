<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decision D1: returned medicine is quarantined, never put back on sale automatically.
     *
     * - product_sizes.quarantined_stock: returned units held apart; never counted by checkout,
     *   the catalog or stock totals (they all read product_sizes.stock).
     * - stock_movements.bucket: which quantity a ledger row describes ("sellable" = stock,
     *   "quarantine" = quarantined_stock). Existing rows are all "sellable".
     * - return_request_items: the units received back for each order line of an accepted return.
     * - return_requests.approved_amount / decided_by / decided_at: the refund the admin approved.
     */
    public function up(): void
    {
        Schema::table('product_sizes', function (Blueprint $table) {
            $table->unsignedInteger('quarantined_stock')->default(0)->after('stock');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('bucket', 16)->default('sellable')->after('type');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->decimal('approved_amount', 10, 2)->nullable()->after('refund_amount');
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');

        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['approved_amount', 'decided_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('bucket');
        });

        Schema::table('product_sizes', function (Blueprint $table) {
            $table->dropColumn('quarantined_stock');
        });
    }
};
