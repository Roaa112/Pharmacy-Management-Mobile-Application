<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class HealthRequest extends FormRequest
{
    protected function today(): string
    {
        return StoreClock::now()->toDateString();
    }

    protected function endOfToday(): string
    {
        return StoreClock::now()->endOfDay()->toDateTimeString();
    }
}
