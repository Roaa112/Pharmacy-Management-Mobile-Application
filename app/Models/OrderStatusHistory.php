<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Order status history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Order status history cannot be deleted.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
