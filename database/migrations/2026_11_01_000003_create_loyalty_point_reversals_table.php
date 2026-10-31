<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decision D2: points taken back when an order is refunded, one row per accepted refund.
     * orders.points_earned is never changed; an order's remaining points are
     * points_earned minus the sum of its reversals. The unique return_request_id makes
     * reversing the same refund twice impossible.
     */
    public function up(): void
    {
        Schema::create('loyalty_point_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedInteger('points');
            $table->decimal('refunded_total', 10, 2);       // all accepted refunds of the order so far
            $table->unsignedInteger('reversed_total');       // all points reversed for the order so far
            $table->string('points_status_at_reversal', 20); // earned / redeemed / expired
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'points_status_at_reversal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_reversals');
    }
};
