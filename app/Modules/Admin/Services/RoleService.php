<?php

namespace App\Modules\Admin\Services;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Permission;
use App\Models\Role;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to roles and their permissions. The route permission (roles.manage) says who may
 * edit roles at all; this service decides what they may do with them, so a request crafted by
 * hand gets the same answer as the screen:
 *  - the super admin role always has every permission: it cannot be edited, deactivated or deleted;
 *  - an admin who is not a super admin can only touch roles whose permissions they hold
 *    themselves, can only grant permissions they hold, and cannot change their own role
 *    (nobody grants themselves anything);
 *  - system roles cannot be deleted, nor can a role that admins still have.
 * Each change is written to the audit log with what it was before and after.
 */
class RoleService
{
    public function create(Admin $actor, array $data): Role
    {
        $slugs = $this->validatedPermissions($actor, $data['permissions'] ?? []);

        return DB::transaction(function () use ($actor, $data, $slugs) {
            $role = new Role(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true)]);
            $role->slug = $this->uniqueSlug($data['name']);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

            AdminAuditLog::record('role.create', $role, ['after' => $this->snapshot($role->fresh())]);

            return $role;
        });
    }

    public function update(Admin $actor, Role $role, array $data): Role
    {
        $this->guardEditable($actor, $role, allowSuperRoleText: true);

        return DB::transaction(function () use ($actor, $role, $data) {
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $before = $this->snapshot($role);

            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            if (!$role->is_super_admin) {
                $active = (bool) ($data['is_active'] ?? $role->is_active);
                $this->guardDeactivation($actor, $role, $active);
                $role->is_active = $active;
                if (array_key_exists('permissions', $data)) {
                    $role->permissions()->sync(Permission::whereIn('slug', $this->validatedPermissions($actor, $data['permissions'] ?? [], $role))->pluck('id'));
                }
            }
            $role->save();

            $after = $this->snapshot($role->fresh());
            AdminAuditLog::record('role.update', $role, $this->diff($before, $after));

            return $role;
        });
    }

    /** One cell of the permission matrix. */
    public function setPermission(Admin $actor, Role $role, Permission $permission, bool $granted): Role
    {
        $this->guardEditable($actor, $role);
        $this->validatedPermissions($actor, [$permission->slug], $role);

        return DB::transaction(function () use ($role, $permission, $granted) {
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $had = $role->permissions()->whereKey($permission->id)->exists();

            if ($had === $granted) {
                return $role;
            }

            $granted ? $role->permissions()->attach($permission->id) : $role->permissions()->detach($permission->id);
            $role->touch();

            AdminAuditLog::record('role.permissions', $role, [
                'added' => $granted ? [$permission->slug] : [],
                'removed' => $granted ? [] : [$permission->slug],
            ]);

            return $role;
        });
    }

    public function duplicate(Admin $actor, Role $role): Role
    {
        $slugs = $role->is_super_admin ? [] : $role->permissions()->pluck('slug')->all();
        $name = $this->uniqueName('Copy of '.$role->name);

        return $this->create($actor, [
            'name' => $name,
            'description' => $role->description,
            'is_active' => false, // a copy starts inactive until it is reviewed
            'permissions' => $slugs,
        ]);
    }

    public function toggle(Admin $actor, Role $role): Role
    {
        $this->guardEditable($actor, $role);

        return DB::transaction(function () use ($actor, $role) {
            $role = Role::lockForUpdate()->findOrFail($role->id);
            $this->guardDeactivation($actor, $role, !$role->is_active);
            $role->forceFill(['is_active' => !$role->is_active])->save();

            AdminAuditLog::record($role->is_active ? 'role.activate' : 'role.deactivate', $role, ['admins' => $role->admins()->count()]);

            return $role;
        });
    }

    public function delete(Admin $actor, Role $role): void
    {
        $this->guardEditable($actor, $role);

        DB::transaction(function () use ($role) {
            $role = Role::lockForUpdate()->findOrFail($role->id);

            if ($role->is_system) {
                throw new DomainException('System roles cannot be deleted. Deactivate it or edit its permissions instead.');
            }
            if ($role->admins()->exists()) {
                throw new DomainException('Move the admins who have this role to another role before deleting it.');
            }

            $snapshot = $this->snapshot($role);
            $role->permissions()->detach();
            $role->delete();

            AdminAuditLog::record('role.delete', $role, ['before' => $snapshot]);
        });
    }

    /** Whether $actor may change $role at all (the screen uses it to disable controls). */
    public function canEdit(Admin $actor, Role $role): bool
    {
        try {
            $this->guardEditable($actor, $role);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function guardEditable(Admin $actor, Role $role, bool $allowSuperRoleText = false): void
    {
        if ($role->is_super_admin && !($allowSuperRoleText && $actor->isSuperAdmin())) {
            throw new DomainException('The super admin role always has every permission and cannot be changed.');
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->role === $role->slug) {
            throw new DomainException('You cannot change your own role.');
        }

        $beyond = array_diff($role->permissions()->pluck('slug')->all(), $actor->permissionSlugs());
        if ($beyond) {
            throw new DomainException('This role has permissions you do not have ('.implode(', ', array_slice($beyond, 0, 5)).'), so only a super admin can change it.');
        }
    }

    private function guardDeactivation(Admin $actor, Role $role, bool $active): void
    {
        if (!$active && $role->is_super_admin) {
            throw new DomainException('The super admin role cannot be deactivated.');
        }
        if (!$active && $actor->role === $role->slug) {
            throw new DomainException('You cannot deactivate your own role.');
        }
    }

    /**
     * Only known permissions; an admin who is not a super admin can grant only what they hold.
     *
     * @return list<string>
     */
    private function validatedPermissions(Admin $actor, array $slugs, ?Role $role = null): array
    {
        $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
        $known = Permission::whereIn('slug', $slugs)->pluck('slug')->all();

        if ($unknown = array_diff($slugs, $known)) {
            throw new DomainException('Unknown permissions: '.implode(', ', $unknown).'.');
        }

        if (!$actor->isSuperAdmin()) {
            if ($beyond = array_diff($slugs, $actor->permissionSlugs())) {
                throw new DomainException('You can only grant permissions you have yourself (not: '.implode(', ', array_slice($beyond, 0, 5)).').');
            }
            if ($role && $actor->role === $role->slug) {
                throw new DomainException('You cannot change your own role.');
            }
        }

        return $slugs;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 24, '') ?: 'role';
        $slug = $base;

        for ($i = 2; Role::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }

    private function uniqueName(string $name): string
    {
        $candidate = Str::limit($name, 76, '');

        for ($i = 2; Role::where('name', $candidate)->exists(); $i++) {
            $candidate = Str::limit($name, 72, '').' '.$i;
        }

        return $candidate;
    }

    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->is_super_admin ? ['*'] : $role->permissions()->orderBy('slug')->pluck('slug')->all(),
        ];
    }

    private function diff(array $before, array $after): array
    {
        return [
            'before' => array_diff_key($before, ['permissions' => 1]),
            'after' => array_diff_key($after, ['permissions' => 1]),
            'added' => array_values(array_diff($after['permissions'], $before['permissions'])),
            'removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
        ];
    }
}
