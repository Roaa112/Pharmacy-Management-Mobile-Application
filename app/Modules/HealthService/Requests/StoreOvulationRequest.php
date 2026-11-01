<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOvulationRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            'start_day_of_cycle' => 'required|date|before_or_equal:'.$this->today().'|after_or_equal:'.StoreClock::now()->subYear()->toDateString(),
            'cycle_length' => 'required|integer|min:21|max:35',
            'period_length' => 'required|integer|min:3|max:10',
        ];
    }
}
