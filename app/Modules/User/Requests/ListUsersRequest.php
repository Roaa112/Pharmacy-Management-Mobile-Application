<?php

namespace App\Modules\User\Requests;

use App\Modules\Shared\Requests\BaseGetRequestValidator;


class ListUsersRequest extends BaseGetRequestValidator
{
    public function rules(): array
    {
        $rules = [
          
        ];
        return array_merge(parent::rules(), $rules);
    }
}
