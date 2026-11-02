<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  - users.otp_attempts: wrong-code attempts against the current OTP, counted with one atomic
 *    conditional UPDATE before the code is checked (a cache counter checked after the guess let
 *    parallel requests all guess before the limit applied).
 *  - Indexes for the dashboard, reports and listings that filter orders by status, payment status
 *    and date (orders had only foreign-key indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // The (user_id, created_at) index may be what MySQL uses for the user_id foreign key:
            // give the key its own index back before dropping the composite one.
            $table->index('user_id', 'orders_user_id_fk_index');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['payment_status', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('otp_attempts');
        });
    }
};
