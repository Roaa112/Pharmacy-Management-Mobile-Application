<?php
namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Translatable;
use Illuminate\Support\Carbon;

/**
 * A marketing banner: display content only, never part of pricing. Shown to customers only
 * while active, inside its dates (store clock), for their country and device.
 */
class Banner extends Model
{
    use Translatable;

    public const POSITIONS = ['home', 'home_middle', 'home_bottom', 'category', 'offers'];
    public const DEVICES = ['all', 'ios', 'android', 'web'];

    protected $fillable = [
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'image',
        'link',
        'status',
        'order_of_appearance',
        'position',
        'starts_at',
        'ends_at',
        'country_id',
        'device',
    ];
    protected $appends = ['title', 'description', 'image_url'];
    protected $hidden = ['title_en', 'title_ar', 'description_en', 'description_ar'];

    protected $casts = ['status' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function getImageUrlAttribute()
    {
        return $this->image
            ? asset( $this->image)
            : null;
    }
    public function getTitleAttribute()
    {
        return $this->getTranslatedAttribute('title');
    }

    public function getDescriptionAttribute()
    {
        return $this->getTranslatedAttribute('description');
    }

    /** What customers may see now: active, scheduled window, country and device. */
    public function scopeVisible(Builder $query, ?string $device = null, ?Carbon $now = null): Builder
    {
        return static::applyVisibility($query, 'status', $device, $now);
    }

    public function state(): string
    {
        return static::stateOf((bool) $this->status, $this->starts_at, $this->ends_at);
    }

    /** Shared by banners and opening ads. */
    public static function applyVisibility(Builder $query, string $activeColumn, ?string $device, ?Carbon $now): Builder
    {
        $now ??= StoreClock::now();
        $country = Country::default();

        return $query->where($activeColumn, true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(fn ($q) => $q->whereNull('country_id')->when($country, fn ($q) => $q->orWhere('country_id', $country->id)))
            ->when($device && in_array($device, self::DEVICES, true) && $device !== 'all',
                fn ($q) => $q->whereIn('device', ['all', $device]));
    }

    public static function stateOf(bool $active, ?Carbon $starts, ?Carbon $ends): string
    {
        $now = StoreClock::now();

        return match (true) {
            !$active => 'inactive',
            $starts && $starts->gt($now) => 'scheduled',
            $ends && $ends->lte($now) => 'expired',
            default => 'active',
        };
    }
}
