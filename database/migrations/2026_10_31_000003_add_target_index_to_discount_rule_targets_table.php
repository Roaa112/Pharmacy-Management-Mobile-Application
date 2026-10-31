<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Every product price lookup searches discount_rule_targets by (target_type, target_id).
    public function up(): void
    {
        Schema::table('discount_rule_targets', function (Blueprint $table) {
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('discount_rule_targets', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
        });
    }
};
