<?php

namespace App\Models;
use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use Translatable;
    protected $fillable = ['name_en', 'name_ar',  'image'];


    protected $appends = ['name','image_url'];
    protected $hidden = ['name_en', 'name_ar'];
  
    public function getImageUrlAttribute()
    {
        return $this->image 
            ? asset( $this->image) 
            : null;
    }
    public function getNameAttribute()
    {
        return $this->getTranslatedAttribute('name');
    }
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
