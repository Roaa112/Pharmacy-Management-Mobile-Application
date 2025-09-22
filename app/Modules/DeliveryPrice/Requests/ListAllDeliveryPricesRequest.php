<?php

namespace App\Modules\DeliveryPrice\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllDeliveryPricesRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [

            'price' => 'price',
            'governorate' => 'governorate',

        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
