<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use Notifiable;

    // role is not mass assignable: only the admin management screen sets it, explicitly.
    protected $fillable = ['name', 'phone', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array{
        return [
            'password'=>"hashed",
            'is_active' => 'boolean',
        ];
    }

    public function roleEnum(): AdminRole
    {
        // An unknown or missing role grants nothing beyond what a manager has.
        return AdminRole::tryFrom((string) ($this->role ?? AdminRole::SuperAdmin->value)) ?? AdminRole::Manager;
    }

    public function hasPermission(string $permission): bool
    {
        return ($this->is_active ?? true) && $this->roleEnum()->allows($permission);
    }
}
