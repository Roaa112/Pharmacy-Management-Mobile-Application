<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBodyWeightRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            'height' => 'required|numeric|gt:0|max:300',
            'weight' => 'required|numeric|gt:0|max:1000',
            // metric = cm/kg, imperial = inches/pounds. Anything else used to be treated as imperial.
            'unit' => 'required|string|in:metric,imperial',
        ];
    }
}
