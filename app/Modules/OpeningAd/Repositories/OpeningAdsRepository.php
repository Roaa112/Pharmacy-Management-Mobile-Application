<?php

namespace App\Modules\OpeningAd\Repositories;

use App\Models\OpeningAd;
use App\Modules\Shared\Repositories\BaseRepository;

class OpeningAdsRepository extends BaseRepository
{
    public function __construct(private OpeningAd $model)
    {
        parent::__construct($model);
    }
}
