<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reliable provider-event processing and duplicate-capture tracking.
 *
 *  - payment_events: an event is claimed (result "processing", processing_started_at) before it is
 *    applied, so two workers never apply it at once and an event left "processing" by a killed
 *    process can be claimed again once stale; attempts counts the claims.
 *  - payments.duplicate_of_payment_id: a payment captured for an order that was already paid by
 *    another attempt (the money is refunded; the order keeps the first payment).
 *
 * Additive only: existing rows keep their meaning (a "received" event is simply retryable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('result');
            $table->timestamp('processing_started_at')->nullable()->after('attempts');
            $table->index(['result', 'processing_started_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('duplicate_of_payment_id')->nullable()->after('attempt')->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_payment_id');
        });

        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex(['result', 'processing_started_at']);
            $table->dropColumn(['attempts', 'processing_started_at']);
        });
    }
};
