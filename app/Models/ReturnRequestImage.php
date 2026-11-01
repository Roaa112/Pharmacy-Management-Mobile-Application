<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Modules\Admin\SignedFileController;


class ReturnRequestImage extends Model
{
    protected $fillable = ['return_request_id', 'path'];
    protected $appends = ['full_url'];
    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }
    /** An expiring signed link: the photo is a private file (see SignedFileController). */
    public function getFullUrlAttribute()
    {
        return $this->path && $this->exists ? SignedFileController::url('return-image', $this->id) : null;
    }

}


