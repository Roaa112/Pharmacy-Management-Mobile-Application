<?php

namespace App\Modules\ContactMessage\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreContactMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['required', 'string'],
        ];
    }
}

