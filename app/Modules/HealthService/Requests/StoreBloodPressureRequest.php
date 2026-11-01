<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBloodPressureRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            'systolic' => 'required|integer|min:1|max:400',
            'diastolic' => 'required|integer|min:1|max:300',
            'condition_type' => 'required|string|max:100',
            'measured_at' => 'required|date|before_or_equal:'.$this->endOfToday(),
        ];
    }
}
