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
     Schema::create('return_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('order_id')->constrained()->onDelete('cascade');
    $table->string('reason');
    $table->text('note')->nullable();
    $table->decimal('refund_amount', 10, 2)->nullable();
    $table->string('refund_method')->nullable();
    $table->string('address')->nullable();
    $table->string('status')->default('pending'); 
    $table->timestamps();
});


Schema::create('return_request_images', function (Blueprint $table) {
    $table->id();
    $table->foreignId('return_request_id')->constrained()->onDelete('cascade');
    $table->string('path');
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};
