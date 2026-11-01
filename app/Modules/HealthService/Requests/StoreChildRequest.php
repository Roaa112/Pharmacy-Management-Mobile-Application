<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChildRequest extends HealthRequest
{
    public function rules(): array
    {
        return [
            'child_name' => 'required|string|max:100',
            // "ذكر" / "أنثى" as before, or male / female.
            'gender' => ['required', Rule::in(Gender::acceptedInputs())],
            'birth_date' => 'required|date|before_or_equal:'.$this->today().'|after_or_equal:'.StoreClock::now()->subYears(18)->toDateString(),
        ];
    }
}
