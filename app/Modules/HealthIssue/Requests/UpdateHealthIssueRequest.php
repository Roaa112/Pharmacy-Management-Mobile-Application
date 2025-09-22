<?php

namespace App\Modules\HealthIssue\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateHealthIssueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
 'name_en' => 'required|string|max:255',
    'name_ar' => 'required|string|max:255',
    'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }
}
