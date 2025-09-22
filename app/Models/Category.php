<?php

namespace App\Models;
use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Translatable;
    protected $fillable = ['name_en', 'name_ar', 'parent_id',  'image' ];


    protected $appends = ['name','image_url'];
    protected $hidden = ['name_en', 'name_ar'];
    

    
    public function getImageUrlAttribute()
    {
        return $this->image 
            ? asset($this->image) 
            : null;
    }
    public function getNameAttribute()
    {
        return $this->getTranslatedAttribute('name');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function subcategories()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
