<?php

use App\Enums\AdminRole;
use App\Modules\Admin\Authorization\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles and permissions move from code (App\Enums\AdminRole) to the database. The seven
     * existing roles become system roles with the fine-grained equivalent of what they could do,
     * and admins keep pointing at their role by slug (admins.role), so nobody gains or loses
     * access. Unknown role values (never valid) become "manager", which the old code fell back to.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->string('module', 40)->index();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        app(RbacSynchronizer::class)->sync();

        DB::table('admins')->whereNull('role')->update(['role' => AdminRole::SuperAdmin->value]);
        DB::table('admins')->whereNotIn('role', AdminRole::values())->update(['role' => AdminRole::Manager->value]);

        Schema::table('admins', function (Blueprint $table) {
            $table->index('role');
            // A role in use cannot be deleted; role slugs never change.
            $table->foreign('role')->references('slug')->on('roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['role']);
            $table->dropIndex(['role']);
        });

        // Admins on custom roles go back to the closest old behaviour.
        DB::table('admins')->whereNotIn('role', AdminRole::values())->update(['role' => AdminRole::Manager->value]);

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
