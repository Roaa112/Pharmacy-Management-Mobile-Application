<?php

namespace App\Modules\OpeningAd\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateOpeningAdRequest extends FormRequest
{
    public function rules(): array
    {

        return [

            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'is_active' => 'sometimes|boolean',

        ];
    }
}
