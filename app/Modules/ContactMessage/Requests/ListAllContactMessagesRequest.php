<?php

namespace App\Modules\ContactMessage\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllContactMessagesRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            
           
          
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));
        return array_merge($this->constructBaseGetQuery($queryParameters), ['filters' => $filters]);
    }
}
