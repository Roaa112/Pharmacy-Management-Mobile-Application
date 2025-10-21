<?php

namespace App\Modules\Medication\Requests;

use App\Modules\Shared\Requests\BaseRequest;

namespace App\Modules\Medication\Requests;

use App\Modules\Shared\Requests\BaseRequest;

class ListAllMedicationsRequest extends BaseRequest
{
    public function getFilters(): array
    {
        return [
            'name'         => ['type' => 'string'],
            'day'          => ['type' => 'string'], 
            'repeat_type'  => ['type' => 'string'], 
        ];
    }

    public function constructQueryCriteria(array $queryParameters)
    {
        $filters = $this->setFilters(data_get($queryParameters, 'filters'));

        return array_merge(
            $this->constructBaseGetQuery($queryParameters), 
            ['filters' => $filters]
        );
    }
}
