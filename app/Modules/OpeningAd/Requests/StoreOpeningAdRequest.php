<?php

namespace App\Modules\OpeningAd\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreOpeningAdRequest extends FormRequest
{
    public function rules(): array
    {
        return [

             'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
             'is_active' => 'sometimes|boolean',
        ];
    }
}

