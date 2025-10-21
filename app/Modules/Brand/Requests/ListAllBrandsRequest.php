<?php

namespace App\Modules\Brand\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllBrandsRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
            'name_en' =>  'name_en',
            'name_ar' =>  'name_ar',
            'image' =>  'image',
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
