<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $fillable = ['stock_count_id', 'product_size_id', 'counted_quantity', 'expected_quantity', 'difference'];

    protected $casts = ['counted_quantity' => 'integer', 'expected_quantity' => 'integer', 'difference' => 'integer'];

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
