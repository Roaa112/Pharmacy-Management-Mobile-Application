<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    protected $fillable = ['country_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function locations()
    {
        return $this->hasMany(DeliveryZoneLocation::class);
    }

    public function rates()
    {
        return $this->hasMany(DeliveryRate::class);
    }
}
