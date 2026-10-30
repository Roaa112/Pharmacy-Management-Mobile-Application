<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Checkout accepts "qicard" but the column never allowed it, so those orders failed to insert.
            $table->enum('payment_method', ['cash', 'zaincash', 'qicard'])->change();

            // Deleting an address or a coupon must never delete the orders that used it.
            $table->dropForeign(['address_id']);
            $table->foreign('address_id')->references('id')->on('addresses')->nullOnDelete();

            $table->dropForeign(['coupon_id']);
            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['address_id']);
            $table->foreign('address_id')->references('id')->on('addresses')->cascadeOnDelete();

            $table->dropForeign(['coupon_id']);
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });
    }
};
