<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
  protected $fillable = [
        'user_id', 'address', 'governorate', 'city', 'city_id', 'area_id', 'street', 'building',
        'apartment', 'landmark', 'type', 'is_default'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cityModel()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    /** What an order keeps of this address at checkout. */
    public function snapshot(): array
    {
        return [
            'governorate' => $this->governorate,
            'city' => $this->city,
            'city_id' => $this->city_id,
            'area_id' => $this->area_id,
            'street' => $this->street,
            'building' => $this->building,
            'apartment' => $this->apartment,
            'landmark' => $this->landmark,
            'address' => $this->address,
            'type' => $this->type,
        ];
    }
}
