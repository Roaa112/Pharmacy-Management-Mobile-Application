<?php

namespace App\Modules\HealthService\Requests;

use App\Enums\BloodSugarCondition;
use App\Enums\Gender;
use App\Support\StoreClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Today" for health dates is the pharmacy's local day (StoreClock), as everywhere else. */
