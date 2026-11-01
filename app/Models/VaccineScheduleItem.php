<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\CarbonInterface;

/**
 * One entry of the vaccination schedule template. Children's vaccines are copied from these
 * when the child is added and never rewritten when the template changes.
 */
class VaccineScheduleItem extends Model
{
    use SoftDeletes;

    public const UNITS = ['day', 'week', 'month', 'year'];

    protected $fillable = [
        'country_id', 'code', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'offset_value', 'offset_unit', 'reminder_days_before', 'sort_order', 'is_active', 'version',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** Fields whose change makes a new version (what a child's copy was made from). */
    public const VERSIONED = ['name_ar', 'name_en', 'description_ar', 'description_en', 'offset_value', 'offset_unit', 'reminder_days_before'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function scheduledDateFor(CarbonInterface $birthDate): CarbonInterface
    {
        // Same arithmetic as before: Carbon::add("2 months") on the birth date.
        return $birthDate->copy()->add("{$this->offset_value} {$this->offset_unit}s");
    }

    protected static function booted()
    {
        static::updating(function (self $item) {
            if ($item->isDirty(self::VERSIONED)) {
                $item->version = (int) $item->getOriginal('version') + 1;
            }
        });
    }
}
