<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;


class Media extends Model
{
    protected $fillable = ['url', 'type'];

    public function mediaable(): MorphTo
    {
        return $this->morphTo();
    }
}
