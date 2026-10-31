<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the customer bought, as it was named at checkout. price_at_time already keeps the
     * price; these keep the product name and size label if the product is later edited or deleted.
     * Existing rows stay NULL (their original names are unknown) and fall back to the live product.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name_ar')->nullable()->after('product_size_id');
            $table->string('product_name_en')->nullable()->after('product_name_ar');
            $table->string('size_label')->nullable()->after('product_name_en');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_name_ar', 'product_name_en', 'size_label']);
        });
    }
};
