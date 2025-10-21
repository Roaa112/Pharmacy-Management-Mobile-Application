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
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('discount_type', ['fixed', 'percent', 'buy_x_get_y', 'amount_gift']);
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->integer('min_quantity')->nullable();
            $table->integer('free_quantity')->nullable();
            $table->string('applies_to_type'); // product, brand, category
            $table->unsignedBigInteger('applies_to_id');
            $table->string('gift_type')->nullable(); // product, brand, category
            $table->unsignedBigInteger('gift_id')->nullable();
            $table->integer('gift_quantity')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_rule_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->onDelete('cascade');
            $table->string('target_type'); // product, brand, category
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rule_targets');
        Schema::dropIfExists('discount_rules');
    }
};
