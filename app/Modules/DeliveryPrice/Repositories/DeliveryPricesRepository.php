<?php

namespace App\Modules\DeliveryPrice\Repositories;

use App\Models\DeliveryPrice;
use App\Modules\Shared\Repositories\BaseRepository;

class DeliveryPricesRepository extends BaseRepository
{
    public function __construct(private DeliveryPrice $model)
    {
        parent::__construct($model);
    }
}
