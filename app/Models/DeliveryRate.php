<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DeliveryRate extends Model
{
    protected $fillable = [
        'delivery_zone_id', 'delivery_provider_id', 'fee', 'free_over_amount', 'min_order_amount',
        'eta_min_days', 'eta_max_days', 'priority', 'is_active', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function zone()
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function provider()
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    /** Active and inside its (optional) effective dates on the store clock. */
    public function scopeEffective(Builder $query, Carbon $at): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }
}
