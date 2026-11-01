<?php

namespace App\Modules\OpeningAd\Requests;

use App\Models\Banner;
use App\Models\OpeningAd;
use App\Support\MarketingImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningAdRequest extends FormRequest
{
    protected bool $imageRequired = true;

    public function rules(): array
    {
        return [
            'image' => array_merge([$this->imageRequired ? 'required' : 'nullable'], MarketingImage::RULES),
            'is_active' => 'sometimes|boolean',
            'title_en' => 'nullable|string|max:255',
            'title_ar' => 'nullable|string|max:255',
            'content_en' => 'nullable|string|max:2000',
            'content_ar' => 'nullable|string|max:2000',
            'link' => 'nullable|url:http,https|max:255',
            'display_frequency' => ['nullable', Rule::in(OpeningAd::FREQUENCIES)],
            'priority' => 'nullable|integer|min:-1000|max:1000',
            'device' => ['nullable', Rule::in(Banner::DEVICES)],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ];
    }
}
