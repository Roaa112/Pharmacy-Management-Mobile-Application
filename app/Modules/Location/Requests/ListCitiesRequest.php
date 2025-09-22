<?php

namespace App\Modules\Location\Requests;

use App\Modules\Shared\Requests\BaseGetRequestValidator;


class ListCitiesRequest extends BaseGetRequestValidator
{
    public function rules(): array
    {
        $rules = [
            'name' => 'null|string',
        ];
        return array_merge(parent::rules(), $rules);
    }
}
