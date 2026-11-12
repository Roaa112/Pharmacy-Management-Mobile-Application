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
        // Accepted ranges are Health › Settings (defaults: cycle 21-35 days, period 3-10 days).
        $settings = \App\Modules\HealthService\Services\HealthSettings::current();
        [$cycleMin, $cycleMax] = $settings->cycleLengthRange();
        [$periodMin, $periodMax] = $settings->periodLengthRange();

        return [
            'start_day_of_cycle' => 'required|date|before_or_equal:'.$this->today().'|after_or_equal:'.StoreClock::now()->subYear()->toDateString(),
            'cycle_length' => "required|integer|min:{$cycleMin}|max:{$cycleMax}",
            'period_length' => "required|integer|min:{$periodMin}|max:{$periodMax}",
        ];
    }
}
