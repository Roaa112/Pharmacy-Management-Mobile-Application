<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBloodSugarRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|numeric|min:0|max:2000',
            // The code (fasting, after_meal...) or the Arabic wording older apps send. Other values
            // are still accepted (the app has always been able to send them) and evaluated as
            // "unknown" unless the value is low; condition_code is then null.
            'condition_type' => ['required', 'string', 'max:100'],
            'measured_at' => 'required|date|before_or_equal:'.$this->endOfToday(),
        ];
    }
}
