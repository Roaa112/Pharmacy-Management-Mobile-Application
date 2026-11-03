<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_deliveries: a worker claims a reminder (status "delivering", claimed_at) with one
 * conditional update before sending it, so two workers never send the same reminder; attempts
 * caps the retries. The index serves the scheduler's search for lost or stale deliveries.
 * Existing rows keep their status (sending rows are picked up by that search).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('status');
            $table->unsignedSmallInteger('attempts')->default(0)->after('claimed_at');
            $table->index(['status', 'updated_at'], 'notification_deliveries_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('notification_deliveries_recovery_index');
            $table->dropColumn(['claimed_at', 'attempts']);
        });
    }
};
