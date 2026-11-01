<?php

namespace App\Models;

use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The popup shown when the app opens. Display content only; the app decides how often to show
 * it from display_frequency. Visible only while active, scheduled, and for the country/device.
 */
class OpeningAd extends Model
{
    use Translatable;

    public const FREQUENCIES = ['every_open', 'once_per_session', 'once_per_day', 'once'];

    protected $table = 'opening_ads';

    protected $fillable = [
        'image',
        'is_active',
        'title_en',
        'title_ar',
        'content_en',
        'content_ar',
        'link',
        'display_frequency',
        'priority',
        'starts_at',
        'ends_at',
        'country_id',
        'device',
    ];


    protected $appends = ['image_url', 'title', 'content'];

    protected $hidden = ['title_en', 'title_ar', 'content_en', 'content_ar'];


    public function getImageUrlAttribute()
    {
        return $this->image
            ? asset($this->image)
            : null;
    }

    public function getTitleAttribute()
    {
        return $this->getTranslatedAttribute('title');
    }

    public function getContentAttribute()
    {
        return $this->getTranslatedAttribute('content');
    }

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeVisible(Builder $query, ?string $device = null, ?Carbon $now = null): Builder
    {
        return Banner::applyVisibility($query, 'is_active', $device, $now);
    }

    public function state(): string
    {
        return Banner::stateOf((bool) $this->is_active, $this->starts_at, $this->ends_at);
    }
}
