<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Units of one order line received back with an accepted return (they go to quarantine).
 */
class ReturnRequestItem extends Model
{
    protected $fillable = ['return_request_id', 'order_item_id', 'quantity'];

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
