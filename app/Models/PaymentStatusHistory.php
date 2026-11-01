<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Payment history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payment history cannot be deleted.'));
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
