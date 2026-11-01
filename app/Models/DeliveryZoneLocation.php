<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A city (area_id null) or one area of a city covered by a zone. */
class DeliveryZoneLocation extends Model
{
    protected $fillable = ['delivery_zone_id', 'city_id', 'area_id'];

    public function zone()
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
