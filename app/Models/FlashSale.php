<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

class FlashSale extends Model
{
    protected $fillable = [
        'discount_value',
        'is_active',
        'date',
        'time',
    ];

    protected $appends = ['starts_at', 'ends_at'];

    public function getStartsAtAttribute()
    {
        return Carbon::parse("{$this->date} {$this->time}");
    }

    public function getEndsAtAttribute()
    {
        // مثلاً الفلاش سيل مدته ساعة، لو حبيتي تخليه configurable قوليلي
        return $this->starts_at->copy()->addHour();
    }

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'saleable');
    }
}
