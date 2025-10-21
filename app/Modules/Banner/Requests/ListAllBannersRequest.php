<?php

namespace App\Modules\Banner\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllBannersRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
            
            'title_en' =>  'title_en',
            'title_ar' =>  'title_ar',
            'link' =>  'link',
            'image' =>  'image',
            'status' =>  'status',
            'order_of_appearance' =>  'order_of_appearance',
          
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
