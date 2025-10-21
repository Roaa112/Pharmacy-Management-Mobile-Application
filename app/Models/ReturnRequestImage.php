<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Support\Facades\Storage;


class ReturnRequestImage extends Model
{
    protected $fillable = ['return_request_id', 'path'];
    protected $appends = ['full_url'];
    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }
 public function getFullUrlAttribute()
{
    return $this->path ? asset(Storage::url($this->path)) : null;
}

}


