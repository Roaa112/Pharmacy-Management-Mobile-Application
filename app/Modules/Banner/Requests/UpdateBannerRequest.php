<?php

namespace App\Modules\Banner\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBannerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'link' => 'nullable|url|max:255', 
            'status' => 'nullable|boolean', 
            'order_of_appearance' => 'nullable|integer', 
        ];
    }
}
