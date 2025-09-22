<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'child_name', 'gender', 'birth_date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vaccines()
    {
        return $this->hasMany(Vaccine::class);
    }
}

