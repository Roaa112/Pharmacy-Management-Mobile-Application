<?php

namespace App\Modules\Shared\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseRequest extends FormRequest
{
    public $baseGetParameters = ['limit', 'offset', 'sort', 'sortBy'];

    public function constructBaseGetQuery($queryParameters)
    {
        $criteria = [];
        foreach ($this->baseGetParameters as $parameter) {
            if (array_key_exists($parameter, $queryParameters)) {
                $criteria[$parameter] = data_get($queryParameters, $parameter);
            }
        }
        return $criteria;
    }

    public function getFilters()
    {
        return [];
    }

public function setFilters($requestFilters)
{
    $finalFilters = [];

    foreach ((array) $requestFilters as $key => $value) {
        $availableFilters = $this->getFilters();

        if (
            array_key_exists($key, $availableFilters)
            && is_array($availableFilters[$key]) // ✅ تأكد أن الفلتر array
        ) {
            $filter = $availableFilters[$key];

            $finalFilters[] = [
                'column'   => $filter['column'],
                'operator' => $filter['operator'],
                'value'    => isset($filter['value']) && is_callable($filter['value'])
                    ? $filter['value']($value)
                    : $value,
            ];
        }
    }

    return $finalFilters;
}


}
