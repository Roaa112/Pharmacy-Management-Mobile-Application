<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A product (every size) or one variant in a flash sale, with an optional unit limit. */
class FlashSaleItem extends Model
{
    protected $fillable = ['flash_sale_id', 'product_id', 'product_size_id', 'quantity_limit', 'is_product_link'];

    protected $casts = ['is_product_link' => 'boolean', 'quantity_limit' => 'integer', 'product_id' => 'integer', 'product_size_id' => 'integer'];

    public function flashSale()
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
