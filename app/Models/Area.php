<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = [
        'name',
        'name_ar',
        'name_en',
        'city_id',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function city() {

        return $this->belongsTo(City::class, 'city_id');
    }
}
