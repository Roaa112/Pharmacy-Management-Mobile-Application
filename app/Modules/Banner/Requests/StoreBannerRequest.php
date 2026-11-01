<?php

namespace App\Modules\Banner\Requests;

use App\Models\Banner;
use App\Support\MarketingImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBannerRequest extends FormRequest
{
    protected bool $imageRequired = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:2000',
            'description_en' => 'nullable|string|max:2000',
            'image' => array_merge([$this->imageRequired ? 'required' : 'nullable'], MarketingImage::RULES),
            'link' => 'nullable|url:http,https|max:255',
            'status' => 'nullable|boolean',
            'order_of_appearance' => 'nullable|integer|min:0|max:10000',
            'position' => ['nullable', Rule::in(Banner::POSITIONS)],
            'device' => ['nullable', Rule::in(Banner::DEVICES)],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ];
    }
}
