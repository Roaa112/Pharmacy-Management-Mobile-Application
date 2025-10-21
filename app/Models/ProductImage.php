<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'image_path'];

    protected $appends = ['image_url']; 

  public function getImageUrlAttribute()
{
    return $this->image_path ? asset($this->image_path) : null;
}

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}