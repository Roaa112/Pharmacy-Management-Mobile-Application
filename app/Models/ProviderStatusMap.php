<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderStatusMap extends Model
{
    protected $fillable = ['delivery_provider_id', 'external_status', 'internal_status'];

    public function provider()
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }
}
