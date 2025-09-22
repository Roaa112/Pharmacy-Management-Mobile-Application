<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BodyWeightResult extends Model
{
    protected $fillable = ['user_id', 'height', 'weight', 'unit', 'bmi_result'];
}
