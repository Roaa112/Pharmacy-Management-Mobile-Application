<?php

namespace App\Modules\Banner\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', 
            'link' => 'nullable|url|max:255', 
            'status' => 'nullable|boolean', 
            'order_of_appearance' => 'nullable|integer', 
        ];
    }
}
