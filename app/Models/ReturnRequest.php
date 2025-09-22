<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $fillable = [
       'user_id', 'order_id', 'reason', 'note', 'refund_amount', 'refund_method', 'address', 'status'
    ];

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
