<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningAd extends Model
{
    protected $table = 'opening_ads';

    protected $fillable = [
        'image',
        'is_active',
    ];


    protected $appends = ['image_url'];


    public function getImageUrlAttribute()
    {
        return $this->image
            ? asset($this->image)
            : null;
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
