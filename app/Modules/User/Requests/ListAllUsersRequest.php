<?php

namespace App\Modules\User\Requests;

use Illuminate\Validation\Rules\Enum;
use App\Modules\User\Enums\UserTypeEnum;
use App\Modules\Shared\Requests\BaseRequest;

class ListAllUsersRequest extends BaseRequest
{
    public function getFilters()
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
