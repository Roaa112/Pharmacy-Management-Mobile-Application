<?php

namespace App\Modules\Banner\Repositories;
use Illuminate\Support\Facades\Storage;
use App\Models\Banner;
use App\Modules\Shared\Repositories\BaseRepository;

class BannersRepository extends BaseRepository
{
    public function __construct(private Banner $model)
    {
        parent::__construct($model);
    }

    
}