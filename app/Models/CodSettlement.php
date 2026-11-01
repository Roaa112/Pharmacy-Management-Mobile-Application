<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cash handed over to the pharmacy for a set of collected COD payments (by a courier or the counter). */
class CodSettlement extends Model
{
    protected $fillable = [
        'reference', 'delivery_provider_id', 'expected_amount', 'received_amount', 'difference',
        'received_reference', 'notes', 'settled_by', 'settled_at',
    ];

    protected $casts = ['settled_at' => 'datetime'];

    public function provider()
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function settler()
    {
        return $this->belongsTo(Admin::class, 'settled_by');
    }
}
