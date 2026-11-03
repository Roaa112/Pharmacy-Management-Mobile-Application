<?php

namespace App\Models;

use App\Modules\Admin\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One capability the code checks (see PermissionRegistry, which RbacSynchronizer copies here).
 * An inactive permission is granted to nobody but the super admin.
 */
class Permission extends Model
{
    protected $fillable = ['name', 'slug', 'module', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function moduleLabel(): string
    {
        return PermissionRegistry::moduleLabel($this->module);
    }
}
