<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A dashboard account. It has one role (admins.role = roles.slug); what it may do is the role's
 * permissions, read from the database on each request, so a change to a role applies on the
 * admin's next request.
 */
class Admin extends Authenticatable
{
    use Notifiable;

    // role is not mass assignable: only AdminAccountService sets it, after its checks.
    protected $fillable = ['name', 'phone', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    // The column defaults, so a just-created model knows them without a refresh.
    protected $attributes = ['role' => AdminRole::SuperAdmin->value, 'is_active' => true];

    /** @var list<string>|null granted permission slugs, resolved once per instance */
    private ?array $grantedPermissions = null;

    protected function casts(): array{
        return [
            'password'=>"hashed",
            'is_active' => 'boolean',
        ];
    }

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'slug');
    }

    /** @deprecated roles are database rows now; kept for older callers. Custom roles read as Manager. */
    public function roleEnum(): AdminRole
    {
        return AdminRole::tryFrom((string) ($this->role ?? AdminRole::SuperAdmin->value)) ?? AdminRole::Manager;
    }

    public function roleName(): string
    {
        return $this->roleModel?->name ?? ucwords(str_replace('_', ' ', (string) $this->role));
    }

    public function isActiveAccount(): bool
    {
        return $this->is_active ?? true;
    }

    /** Super admin by role (active account and active role). */
    public function isSuperAdmin(): bool
    {
        $role = $this->roleModel;

        return $this->isActiveAccount() && $role?->is_super_admin && $role->is_active;
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->grantedPermissions !== null) {
            return $this->grantedPermissions;
        }

        $role = $this->roleModel;

        if (!$this->isActiveAccount() || !$role?->is_active) {
            return $this->grantedPermissions = [];
        }

        return $this->grantedPermissions = $role->grantedSlugs();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionSlugs(), true);
    }

    /** Forget the resolved permissions (after this admin's role or its permissions changed). */
    public function flushPermissions(): static
    {
        $this->grantedPermissions = null;
        $this->unsetRelation('roleModel');

        return $this;
    }
}
