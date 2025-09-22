<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
          
            'sizes' => 'required|array|min:1',
            'sizes.*.size' => 'required|string|max:50',
            'sizes.*.price' => 'required|numeric|min:0',
            'sizes.*.stock' => 'required|integer|min:0',
            'rate' => 'nullable|numeric|between:0,5',
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048', 
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'health_issues' => 'nullable|array',
            'health_issues.*' => 'exists:health_issues,id',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'saleable_type' => 'nullable|string|in:App\Models\FlashSale,App\Models\SomeOtherModel', 
            'saleable_id' => 'nullable|integer',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
