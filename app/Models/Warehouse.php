<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['country_id', 'code', 'name', 'address', 'phone', 'is_sellable', 'is_active', 'is_default'];

    protected $casts = ['is_sellable' => 'boolean', 'is_active' => 'boolean', 'is_default' => 'boolean'];

    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?? static::where('is_active', true)->orderBy('id')->first();
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function balances()
    {
        return $this->hasMany(InventoryBalance::class);
    }

    /** Warehouses whose stock can be sold online (counted in product_sizes.stock). */
    public function scopeSellable($query)
    {
        return $query->where('is_active', true)->where('is_sellable', true);
    }
}
