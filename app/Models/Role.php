<?php

namespace App\Models;

use App\Modules\Admin\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard role: a named set of permissions. Admins reference their role by slug
 * (admins.role), which never changes once created; the name can.
 *
 * System roles (the original seven) cannot be deleted. The super admin role always has every
 * permission: its permission list is not consulted and cannot be edited or deactivated.
 */
class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'role', 'slug');
    }

    /** @return list<string> slugs of the active permissions this role grants */
    public function grantedSlugs(): array
    {
        if ($this->is_super_admin) {
            return PermissionRegistry::slugs();
        }

        return $this->permissions->where('is_active', true)->pluck('slug')->values()->all();
    }
}
