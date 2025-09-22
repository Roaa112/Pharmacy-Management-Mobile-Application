<?php

namespace App\Modules\FlashSale\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllFlashSalesRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            'discount_value' => 'discount_value',
            'is_active' => 'is_active',
            'date' => 'date',
            'time' => 'time',
           
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
