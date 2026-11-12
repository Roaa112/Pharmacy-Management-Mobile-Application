<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The "Health texts" page was removed: medical messages are managed only on Result rules &
 * messages. Databases that ran an earlier version of 2026_11_15_000001 have a health_texts table;
 * it is dropped here (nothing reads it any more). On other databases this does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('health_texts');
    }

    public function down(): void
    {
        // Nothing to restore: the table is no longer used.
    }
};
