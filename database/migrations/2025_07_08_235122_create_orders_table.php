<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('address_id')->constrained()->onDelete('cascade');
            $table->enum('payment_method', ['cash', 'zaincash']);
            $table->string('payment_image')->nullable();
            $table->decimal('delivery_fee', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->text('gift_description')->nullable();
            $table->unsignedBigInteger('gift_rule_id')->nullable(); // ID of applied gift rule
            $table->integer('points_earned')->default(0);
            $table->enum('status', ['pending', 'confirmed', 'canceled'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
