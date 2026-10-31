<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Zain Cash and Qi Card numbers were integer columns: an 11-digit number such as
 * 07800000000 is out of range and its leading zero is lost. The settings form already
 * validates them as text, so they are stored as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('zaincash', 30)->nullable()->change();
            $table->string('keycard', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->integer('zaincash')->nullable()->change();
            $table->integer('keycard')->nullable()->change();
        });
    }
};
