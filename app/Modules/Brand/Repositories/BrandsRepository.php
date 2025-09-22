<?php

namespace App\Modules\Brand\Repositories;

use App\Models\Brand;
use App\Modules\Shared\Repositories\BaseRepository;

class BrandsRepository extends BaseRepository
{
    public function __construct(private Brand $model)
    {
        parent::__construct($model);
    }
}