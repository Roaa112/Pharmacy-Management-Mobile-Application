<?php

namespace App\Models;

use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The store currently operates in Egypt only and uses EGP. The countries table holds that one
 * market (orders record it as country_id + currency_code at checkout); there is no country
 * selection anywhere. Other rows can only exist as history in databases migrated earlier.
 */
class Country extends Model
{
    use Translatable;

    public const STORE_ISO = 'EG';

    protected $fillable = ['iso2', 'name_ar', 'name_en', 'currency_code', 'timezone', 'phone_code', 'week_start', 'is_active', 'is_default'];

    protected $casts = ['is_active' => 'boolean', 'is_default' => 'boolean'];

    public const WEEK_DAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    private static ?self $store = null;

    /** Egypt, the store's only market. */
    public static function default(): ?self
    {
        if (static::$store && static::$store->exists) {
            return static::$store;
        }

        return static::$store = static::where('iso2', self::STORE_ISO)->first();
    }

    public static function flushDefault(): void
    {
        static::$store = null;
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function getNameAttribute()
    {
        return $this->getTranslatedAttribute('name');
    }

    protected static function booted()
    {
        static::saved(fn () => static::flushDefault());
    }
}
