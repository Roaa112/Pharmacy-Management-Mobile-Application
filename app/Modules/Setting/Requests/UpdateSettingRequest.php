<?php

namespace App\Modules\Setting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateSettingRequest extends FormRequest
{
    public function rules(): array
    {
          return [
            'privacy_policy_ar' => ['nullable', 'string'],
            'privacy_policy_en' => ['nullable', 'string'],
            'terms_conditions_ar' => ['nullable', 'string'],
            'terms_conditions_en' => ['nullable', 'string'],
            'facebook' => ['nullable', 'url'],
            'instagram' => ['nullable', 'url'],
            'tiktok' => ['nullable', 'url'],
            'youtube' => ['nullable', 'url'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'map_location_url' => ['nullable', 'url'],
              'spend_x' => ['nullable'],
                  'get_y' => ['nullable'],
                       'zaincash' => ['nullable','string'],
        ];
    }
}
