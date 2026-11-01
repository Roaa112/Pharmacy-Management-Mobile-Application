<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The delivery date promised at checkout (from the rate's ETA). Existing shipments stay null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->date('estimated_delivery_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('estimated_delivery_at'));
    }
};
