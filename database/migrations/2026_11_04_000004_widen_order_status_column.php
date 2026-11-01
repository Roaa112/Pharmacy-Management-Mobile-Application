<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders.status was created as a MySQL ENUM of the five original statuses. The warehouse steps
 * (picking, packed) need more values, and the allowed transitions are enforced by OrderStatus in
 * code, so the column becomes a plain string. No row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // The enum cannot hold the warehouse steps: those orders go back to "confirmed".
        DB::table('orders')->whereIn('status', ['picking', 'packed'])->update(['status' => 'confirmed']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'canceled', 'completed', 'on_delivery'])->default('pending')->change();
        });
    }
};
