<?php

namespace App\Modules\FlashSale\Repositories;

use App\Models\FlashSale;
use App\Modules\Shared\Repositories\BaseRepository;

class FlashSalesRepository extends BaseRepository
{
    public function __construct(private FlashSale $model)
    {
        parent::__construct($model);
    }
}