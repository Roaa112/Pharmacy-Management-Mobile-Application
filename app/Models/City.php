<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Governorate-level location (the addresses' "governorate"). name is the original single-language
 * name; name_ar / name_en are optional translations.
 */
class City extends Model
{
    protected $fillable = [
        'name', 'name_ar', 'name_en', 'country_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function areas() {

        return $this->hasMany(Area::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return (app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en) ?: $this->name;
    }

    /** Exact match on any of the names, used to link legacy free-text addresses. */
    public function scopeNamed($query, ?string $name)
    {
        $name = trim((string) $name);

        return $query->where(fn ($q) => $q->where('name', $name)->orWhere('name_ar', $name)->orWhere('name_en', $name));
    }
}
