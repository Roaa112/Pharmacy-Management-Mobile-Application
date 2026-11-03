<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who did it from where: the browser alongside the IP, and a per-admin timeline index. */
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->string('user_agent', 255)->nullable()->after('ip');
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // MySQL dropped the implicit admin_id foreign-key index once the composite index covered it.
        Schema::table('admin_audit_logs', fn (Blueprint $table) => $table->index('admin_id', 'admin_audit_logs_admin_id_index'));

        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['admin_id', 'created_at']);
            $table->dropColumn('user_agent');
        });
    }
};
