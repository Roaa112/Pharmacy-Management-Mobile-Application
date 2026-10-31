<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $fillable = [
       'user_id', 'order_id', 'reason', 'note', 'refund_amount', 'refund_method', 'address', 'status'
    ];

    // approved_amount, decided_by and decided_at are deliberately not fillable:
    // only ReturnDecisionService sets them, when the admin accepts or rejects.

    protected $casts = ['decided_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function pointReversal()
    {
        return $this->hasOne(LoyaltyPointReversal::class);
    }

    public function images()
    {
        return $this->hasMany(ReturnRequestImage::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
