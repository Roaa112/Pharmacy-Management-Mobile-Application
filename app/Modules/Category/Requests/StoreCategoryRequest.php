<?php

namespace App\Modules\Category\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
             'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', 
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }
}

