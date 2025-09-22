<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BloodSugarMeasurement extends Model
{
    protected $fillable = ['user_id', 'value', 'condition_type', 'measured_at'];

    protected $casts = [
        'measured_at' => 'datetime',
    ];
}

