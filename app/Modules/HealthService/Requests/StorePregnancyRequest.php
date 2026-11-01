<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePregnancyRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            // Up to two years back: older dates are typing mistakes, not pregnancies.
            'last_period_date' => 'required|date|before_or_equal:'.$this->today().'|after_or_equal:'.StoreClock::now()->subYears(2)->toDateString(),
        ];
    }
}
