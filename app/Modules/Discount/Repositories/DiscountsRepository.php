<?php

namespace App\Modules\Discount\Repositories;

use App\Models\Discount;
use App\Models\DiscountRule;
use App\Modules\Shared\Repositories\BaseRepository;

class DiscountsRepository extends BaseRepository
{
    public function __construct(private DiscountRule $model)
    {
        parent::__construct($model);
    }
}
