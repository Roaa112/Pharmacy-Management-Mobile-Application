<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens ,HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'phone', 'email', 'password', 'social_id', 'social_provider',
        'is_verified', 'otp_code', 'otp_expires_at'
    ];



    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'otp_code' , 'remember_token',];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
            'otp_expires_at' => 'datetime',
        ];
    }


    public function toggleStatus()
    {
        $this->is_active = !$this->is_active;
        $this->save();
    }

public function productReviews(): HasMany
{
    return $this->hasMany(ProductReview::class);
}

public function favorites()
{
    return $this->hasMany(Favorite::class);
}

    public function coupons()
{
    return $this->belongsToMany(Coupon::class)->withTimestamps();
}
public function addresses()
{
    return $this->hasMany(Address::class);
}

}
