<?php

namespace App\Modules\Address\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllAddressesRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
            
            'user_id' =>  'user_id',
            'address' =>  'address',
            'governorate' =>  'governorate',
            'city' =>  'city',
            'street' =>  'street',
            'building' =>  'building',
            'apartment' =>  'apartment',
            'landmark' =>  'landmark',
            'type' =>  'type',
            'is_default' =>  'is_default',
           
          
          
        ];
    }

  public function constructQueryCriteria(array $parameters): array
{
    $filters = [];

    if (!empty($parameters['user_id'])) {
        $filters[] = [
            'field' => 'user_id',
            'operator' => '=',
            'value' => $parameters['user_id'],
        ];
    }

    return [
        'filters' => $filters,
        'sort' => [
            ['field' => 'created_at', 'direction' => 'desc'],
        ],
    ];
}

}
