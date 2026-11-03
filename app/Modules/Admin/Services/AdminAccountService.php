<?php

namespace App\Modules\Admin\Services;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Role;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard accounts: creating them, editing them, giving them a role, activating them.
 *
 * Server-side rules (the screen only mirrors them):
 *  - there is always at least one active admin with an active super admin role: the last one
 *    cannot lose the role or be deactivated;
 *  - only a super admin can create, edit or assign super admins;
 *  - changing an admin's role needs roles.assign, and the role cannot carry permissions the
 *    actor does not have (no handing out more than you hold);
 *  - nobody changes their own role or deactivates themselves.
 */
class AdminAccountService
{
    public function create(Admin $actor, array $data): Admin
    {
        $role = $this->assignableRole($actor, $data['role']);

        return DB::transaction(function () use ($data, $role) {
            $admin = Admin::create($data);
            $admin->forceFill(['role' => $role->slug, 'is_active' => true])->save();

            AdminAuditLog::record('admin.create', $admin, ['email' => $admin->email, 'role' => $role->slug]);

            return $admin;
        });
    }

    public function update(Admin $actor, Admin $admin, array $data): Admin
    {
        if ($admin->isSuperAdmin() && !$actor->isSuperAdmin()) {
            throw new DomainException('Only a super admin can change a super admin account.');
        }

        $isSelf = $actor->is($admin);
        $roleChanges = isset($data['role']) && $data['role'] !== $admin->role;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $admin->is_active;

        if ($roleChanges) {
            if ($isSelf) {
                throw new DomainException('You cannot change your own role.');
            }
            if (!$actor->hasPermission('roles.assign')) {
                throw new DomainException('You do not have permission to change roles.');
            }
            $this->assignableRole($actor, $data['role']);
        }
        if ($isSelf && !$active) {
            throw new DomainException('You cannot deactivate your own account.');
        }

        return DB::transaction(function () use ($admin, $data, $roleChanges, $active) {
            // Serialize changes to super admins so two concurrent demotions cannot both pass the check.
            $admin = Admin::lockForUpdate()->findOrFail($admin->id);
            $newRole = $roleChanges ? $data['role'] : $admin->role;
            $before = $admin->only(['name', 'phone', 'role', 'is_active']);

            if ($admin->isSuperAdmin() && (!$active || !Role::where('slug', $newRole)->value('is_super_admin'))) {
                $this->guardNotLastSuperAdmin($admin);
            }

            $admin->fill(['name' => $data['name'] ?? $admin->name, 'phone' => $data['phone'] ?? $admin->phone]);
            if (!empty($data['password'])) {
                $admin->password = $data['password'];
            }
            $admin->forceFill(['role' => $newRole, 'is_active' => $active])->save();
            $admin->flushPermissions();

            $after = $admin->only(['name', 'phone', 'role', 'is_active']);
            AdminAuditLog::record('admin.update', $admin, [
                'before' => $before,
                'after' => $after,
                'password_changed' => !empty($data['password']),
            ]);
            if ($before['role'] !== $after['role']) {
                AdminAuditLog::record('admin.role_change', $admin, ['from' => $before['role'], 'to' => $after['role']]);
            }
            if ((bool) $before['is_active'] !== (bool) $after['is_active']) {
                AdminAuditLog::record($active ? 'admin.activate' : 'admin.deactivate', $admin);
            }

            return $admin;
        });
    }

    /** Roles the actor may hand out (for the form). */
    public function assignableRoles(Admin $actor)
    {
        return Role::with('permissions:id,slug')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Role $role) => $this->mayAssign($actor, $role))->values();
    }

    private function assignableRole(Admin $actor, string $slug): Role
    {
        $role = Role::where('slug', $slug)->first();

        if (!$role || !$role->is_active) {
            throw new DomainException('Choose an active role.');
        }
        if (!$this->mayAssign($actor, $role)) {
            throw new DomainException($role->is_super_admin
                ? 'Only a super admin can make someone a super admin.'
                : 'You cannot assign a role with permissions you do not have.');
        }

        return $role;
    }

    private function mayAssign(Admin $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return !$role->is_super_admin && !array_diff($role->grantedSlugs(), $actor->permissionSlugs());
    }

    private function guardNotLastSuperAdmin(Admin $admin): void
    {
        $others = Admin::query()
            ->whereKeyNot($admin->id)
            ->where('is_active', true)
            ->whereIn('role', Role::where('is_super_admin', true)->where('is_active', true)->select('slug'))
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new DomainException('There must always be at least one active super admin.');
        }
    }
}
