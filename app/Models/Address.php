<?php

namespace App\Models;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
  protected $fillable = [
        'user_id', 'address', 'governorate', 'city', 'street', 'building',
        'apartment', 'landmark', 'type', 'is_default'
    ];
    

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
