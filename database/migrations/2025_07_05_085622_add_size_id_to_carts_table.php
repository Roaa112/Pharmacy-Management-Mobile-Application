<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production already has this column (added outside of migrations).
        if (Schema::hasColumn('carts', 'product_size_id')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('product_size_id')->nullable()->constrained('product_sizes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        //
    }
};
