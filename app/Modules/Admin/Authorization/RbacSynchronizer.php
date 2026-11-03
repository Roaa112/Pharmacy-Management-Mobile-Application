<?php

namespace App\Modules\Admin\Authorization;

use App\Enums\AdminRole;
use Illuminate\Support\Facades\DB;

/**
 * Copies PermissionRegistry into `permissions` and makes sure the system roles exist. Safe to
 * run any number of times (migration, deploy, `php artisan permissions:sync`):
 *  - permissions are created or have their name / module / description refreshed by slug;
 *  - a permission seen for the first time is given to the system roles whose defaults include it;
 *    after that, which role has it is the admins' decision and is never touched again;
 *  - permissions no longer in the code are deactivated, not deleted (roles keep the history).
 *
 * Uses the query builder only, so it behaves the same inside migrations and at runtime.
 */
class RbacSynchronizer
{
    /** @return array{created: int, updated: int, deactivated: int} */
    public function sync(): array
    {
        return DB::transaction(function () {
            $now = now();
            $roleIds = $this->ensureSystemRoles($now);
            $existing = DB::table('permissions')->pluck('id', 'slug');
            $created = $updated = 0;

            foreach (PermissionRegistry::all() as $slug => [$module, $name, $description]) {
                if ($existing->has($slug)) {
                    $updated += DB::table('permissions')->where('slug', $slug)
                        ->update(['module' => $module, 'name' => $name, 'description' => $description, 'is_active' => true, 'updated_at' => $now]);
                    continue;
                }

                $id = DB::table('permissions')->insertGetId([
                    'slug' => $slug, 'module' => $module, 'name' => $name, 'description' => $description,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $created++;

                foreach (AdminRole::cases() as $role) {
                    if ($role !== AdminRole::SuperAdmin && in_array($slug, PermissionRegistry::defaultsFor($role), true)) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleIds[$role->value], 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            $deactivated = DB::table('permissions')->whereNotIn('slug', PermissionRegistry::slugs())->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            return ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated];
        });
    }

    /** @return array<string, int> slug => id */
    private function ensureSystemRoles($now): array
    {
        foreach (AdminRole::cases() as $role) {
            DB::table('roles')->insertOrIgnore([
                'slug' => $role->value,
                'name' => $role->label(),
                'description' => $role->description(),
                'is_system' => true,
                'is_super_admin' => $role === AdminRole::SuperAdmin,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return DB::table('roles')->whereIn('slug', AdminRole::values())->pluck('id', 'slug')->all();
    }
}
