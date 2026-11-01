<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = ['user_id', 'token', 'token_hash', 'platform', 'locale', 'last_used_at'];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
