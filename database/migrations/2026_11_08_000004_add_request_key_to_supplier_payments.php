<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * supplier_payments.request_key: a one-time key carried by the payment form, unique, so a
 * double-submitted or retried form records the payment once. Nullable: older rows and callers
 * without a key are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->string('request_key', 64)->nullable()->unique()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
        });
    }
};
