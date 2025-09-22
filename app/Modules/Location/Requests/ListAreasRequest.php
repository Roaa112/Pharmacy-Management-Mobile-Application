<?php

namespace App\Modules\Location\Requests;

use App\Modules\Shared\Requests\BaseGetRequestValidator;


class ListAreasRequest extends BaseGetRequestValidator
{
    public function rules(): array
    {
        $rules = [
            'cityId' => 'integer',
            'cityName' => 'String',
        ];
        return array_merge(parent::rules(), $rules);
    }
}
