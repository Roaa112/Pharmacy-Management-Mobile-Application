<?php

namespace App\Modules\HealthIssue\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllHealthIssuesRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
            'name' =>  'name',
            'image' =>  'image',
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
