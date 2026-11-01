<?php

namespace App\Models;

use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A marketing campaign: groups promotions under shared dates and eligibility. A promotion in a
 * campaign is live only while both the promotion and the campaign are.
 */
class PromotionCampaign extends Model
{
    use SoftDeletes;

    protected $fillable = ['name_en', 'name_ar', 'description', 'starts_at', 'ends_at', 'is_active', 'country_id', 'customer_scope'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function promotions()
    {
        return $this->hasMany(DiscountRule::class, 'campaign_id');
    }

    public function getNameAttribute(): string
    {
        return (app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name_en) : $this->name_en) ?? '';
    }

    public function isLive(?Carbon $now = null): bool
    {
        return $this->state($now) === 'active' && !$this->trashed();
    }

    /** inactive | scheduled | active | expired, on the store clock. */
    public function state(?Carbon $now = null): string
    {
        $now ??= StoreClock::now();

        return match (true) {
            !$this->is_active => 'inactive',
            $this->starts_at && $this->starts_at->gt($now) => 'scheduled',
            $this->ends_at && $this->ends_at->lt($now) => 'expired',
            default => 'active',
        };
    }
}
