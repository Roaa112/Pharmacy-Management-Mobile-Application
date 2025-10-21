<?php

namespace App\Modules\OpeningAd\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllOpeningAdsRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [

            'image' =>  'image',
            'is_active' => 'is_active',
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
