<?php

namespace App\Modules\DeliveryPrice\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDeliveryPriceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'price' => 'required|numeric',
            'governorate' => 'required',
          
        ];
    }
}

