<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Translatable;

class Banner extends Model
{
    use Translatable;

    protected $fillable = [
        'title_en',
        'title_ar',
        'image',
        'link',
        'status',
        'order_of_appearance',
    ];
    protected $appends = ['title','image_url'];
    protected $hidden = ['title_en', 'title_ar'];
  
    public function getImageUrlAttribute()
    {
    
        return $this->image 
            ? asset( $this->image) 
            : null;
    }
    public function getTitleAttribute()
    {
        return $this->getTranslatedAttribute('title');
    }
}
