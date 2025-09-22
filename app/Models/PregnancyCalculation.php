<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PregnancyCalculation extends Model
{
    protected $fillable = ['user_id', 'last_period_date', 'due_date', 'result'];

    protected $casts = [
        'last_period_date' => 'date',
        'due_date' => 'date',
        'result' => 'array',
    ];
}

