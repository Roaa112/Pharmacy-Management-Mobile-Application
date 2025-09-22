<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BloodPressureMeasurement extends Model
{
    protected $fillable = ['user_id', 'systolic', 'diastolic', 'condition_type', 'measured_at'];

    protected $casts = [
        'measured_at' => 'datetime',
    ];
}

