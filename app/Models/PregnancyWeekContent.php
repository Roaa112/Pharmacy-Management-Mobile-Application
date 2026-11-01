<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Informational content for a range of pregnancy weeks. The week itself is calculated in code. */
class PregnancyWeekContent extends Model
{
    protected $fillable = [
        'week_from', 'week_to', 'size_ar', 'size_en', 'weight_ar', 'weight_en', 'length_ar', 'length_en',
        'image', 'tip_ar', 'tip_en', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public static function forWeek(int $week): ?self
    {
        return static::where('is_active', true)->where('week_from', '<=', $week)->where('week_to', '>=', $week)
            ->orderBy('week_from')->first();
    }

    public function text(string $field, string $locale): ?string
    {
        return $this->{"{$field}_{$locale}"} ?? $this->{"{$field}_ar"};
    }
}
