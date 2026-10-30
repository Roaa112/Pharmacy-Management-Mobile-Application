<?php

use Illuminate\Database\Migrations\Migration;

// The legacy `discounts` table was replaced by `discount_rules` (2025_06_23_120617).
// This migration is kept as a no-op because it is already recorded as run in production.
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
