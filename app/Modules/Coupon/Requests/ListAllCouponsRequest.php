<?php

namespace App\Modules\Coupon\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllCouponsRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
            'name' =>  'name',
          
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
