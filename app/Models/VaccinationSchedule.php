<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VaccinationSchedule extends Model
{
    protected $fillable = ['user_id', 'child_name', 'gender', 'birth_date', 'schedule'];

    protected $casts = [
        'birth_date' => 'date',
        'schedule' => 'array',
    ];
}

