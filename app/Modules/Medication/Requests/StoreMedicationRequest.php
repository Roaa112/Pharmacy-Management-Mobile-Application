<?php

namespace App\Modules\Medication\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreMedicationRequest extends FormRequest
{
    public function rules(): array
    {
    
             return [
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', 
            'quantity_per_time' => 'required|integer|min:1',
            'repeat_type' => 'required|in:every_day,specific_days,once',
            'times' => 'required|array|min:1',
            'times.*' => 'required',
            'days' => 'nullable|array',
            'days.*' => 'in:saturday,sunday,monday,tuesday,wednesday,thursday,friday',
        ];
      
    }
}

