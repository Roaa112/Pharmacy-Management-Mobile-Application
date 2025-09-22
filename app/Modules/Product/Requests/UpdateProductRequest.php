<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
       
            'sizes' => 'required|array|min:1',
            'sizes.*.size' => 'required|string',
            'sizes.*.price' => 'required|numeric',
            'sizes.*.stock' => 'required|integer',
            
            'rate' => 'nullable|numeric|between:0,5',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',

            'health_issues' => 'nullable|array',
            'health_issues.*' => 'exists:health_issues,id',

            'product_images' => 'nullable|array',
            'product_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'saleable_type' => 'nullable|string|in:App\Models\FlashSale,App\Models\SomeOtherModel',
            'saleable_id' => 'nullable|integer',
        ];
    }
}
