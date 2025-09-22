<?php

namespace App\Modules\Product\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllProductsRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            'name_ar' => [
                'column' => 'name_ar',
                'operator' => 'like',
                'value' => fn($value) => "%$value%",
            ],
            'name_en' => [
                'column' => 'name_en',
                'operator' => 'like',
                'value' => fn($value) => "%$value%", 
            ],
            'price_from' => [
                'column' => 'price',
                'operator' => '>=',
            ],
            'price_to' => [
                'column' => 'price',
                'operator' => '<=',
            ],
            'rate' => [
                'column' => 'rate',
                'operator' => '=',
            ],
            'brand_id' => [
                'column' => 'brand_id',
                'operator' => '=',
            ],
            'category_id' => [
                'column' => 'category_id',
                'operator' => '=',
            ],
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
