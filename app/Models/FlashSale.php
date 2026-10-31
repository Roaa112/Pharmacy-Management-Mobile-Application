<?php

namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

class FlashSale extends Model
{
    /** A flash sale lasts this long from its date and time. */
    public const DURATION_MINUTES = 60;

    protected $fillable = [
        'discount_value',
        'is_active',
        'date',
        'time',
    ];

    protected $appends = ['starts_at', 'ends_at'];

    /**
     * Start as the store's wall-clock time (see StoreClock).
     */
    public function getStartsAtAttribute()
    {
        return Carbon::parse($this->date)->setTimeFromTimeString((string) $this->time);
    }

    public function getEndsAtAttribute()
    {
        return $this->starts_at->copy()->addMinutes(self::DURATION_MINUTES);
    }

    /**
     * The only rule for whether the flash sale price applies: enabled, and the store clock
     * is inside [starts_at, ends_at) — live exactly at the start, over exactly at the end.
     */
    public function isLive(?Carbon $at = null): bool
    {
        if (!$this->is_active || !$this->date || !$this->time) {
            return false;
        }

        $at ??= StoreClock::now();

        return $at->gte($this->starts_at) && $at->lt($this->ends_at);
    }

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'saleable');
    }
}
