<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvulationResult extends Model
{
    protected $fillable = ['user_id', 'start_day_of_cycle', 'cycle_length', 'result'];

    protected $casts = [
        'result' => 'array',
        'start_day_of_cycle' => 'date',
    ];
}
