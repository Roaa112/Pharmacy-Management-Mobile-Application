<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * supplier_payments.is_advance: an on-account payment larger than what was owed is an advance
 * the admin confirmed explicitly (it makes the supplier balance a credit). Existing rows are
 * left false: whether an older payment was meant as an advance cannot be told after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->boolean('is_advance')->default(false)->after('request_key');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('is_advance');
        });
    }
};
