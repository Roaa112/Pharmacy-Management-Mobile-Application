<?php

namespace App\Modules\Address\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateAddressRequest extends FormRequest
{
    public function rules(): array
    {
       return [
           
            'governorate'   => ['required', 'string', 'max:255'],
            'city'          => ['required', 'string', 'max:255'],
            'street'        => ['required', 'string', 'max:255'],
            'building'      => ['nullable', 'string', 'max:255'],
            'apartment'     => ['nullable', 'string', 'max:255'],
            'landmark'      => ['nullable', 'string', 'max:255'],
            'address'       => ['nullable', 'string', 'max:255'],
            'type'          => ['required', 'in:home,work,pharmacy'],
            'is_default'    => ['nullable', 'boolean'],
        ];
    }
}
