<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = [
        'title', 'precentage', 'start_date', 'end_date', 'expire_date', 'is_active'
    ];
}
