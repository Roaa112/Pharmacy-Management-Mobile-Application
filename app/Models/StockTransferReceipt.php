<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferReceipt extends Model
{
    protected $fillable = ['stock_transfer_id', 'received_by', 'quantities', 'notes'];

    protected $casts = ['quantities' => 'array'];

    public function receiver()
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }
}
