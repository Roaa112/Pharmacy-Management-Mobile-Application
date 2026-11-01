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
            'city_id'       => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('cities', 'id')->where('is_active', true)->where('country_id', \App\Models\Country::default()?->id)],
            'area_id'       => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('areas', 'id')->where('city_id', $this->input('city_id'))],
            'is_default'    => ['nullable', 'boolean'],
        ];
    }
}
