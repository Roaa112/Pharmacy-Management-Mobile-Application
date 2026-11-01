<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Whoever carries the parcel: our own team or an external company with its own drivers,
 * vehicles and tracking. We only store what we need to hand over and follow a shipment.
 */
class DeliveryProvider extends Model
{
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL_MANUAL = 'external_manual';
    public const TYPE_EXTERNAL_API = 'external_api';
    public const TYPES = [self::TYPE_INTERNAL, self::TYPE_EXTERNAL_MANUAL, self::TYPE_EXTERNAL_API];

    protected $fillable = [
        'country_id', 'name', 'code', 'type', 'adapter', 'credentials', 'webhook_secret',
        'tracking_url_template', 'supports_cod', 'is_active', 'is_default',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'webhook_secret' => 'encrypted',
        'supports_cod' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $hidden = ['credentials', 'webhook_secret'];

    public function statusMaps()
    {
        return $this->hasMany(ProviderStatusMap::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public static function default(): ?self
    {
        return static::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    public function trackingUrlFor(?string $trackingNumber): ?string
    {
        if (!$trackingNumber || !$this->tracking_url_template) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($trackingNumber), $this->tracking_url_template);
    }
}
