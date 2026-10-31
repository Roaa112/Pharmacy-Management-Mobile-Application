<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_size_id',
        'quantity',
        'price_at_time',
        'original_price',
        'total_price',
        'product_name_ar',
        'product_name_en',
        'size_label',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Order history must keep showing the product even after it sells out or is deleted.
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function size()
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }
}
