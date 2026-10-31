<?php

namespace App\Modules\Medication\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateMedicationRequest extends FormRequest
{
    /**
     * Runs before validation: another user's medication is a 404 whatever the payload,
     * so validation errors cannot be used to probe it.
     */
    public function authorize(): bool
    {
        abort_unless((int) $this->route('medication')?->user_id === $this->user()->id, 404);

        return true;
    }

    public function rules(): array
    {

  
    
             return [
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', 
            'quantity_per_time' => 'required|integer|min:1',
            'repeat_type' => 'required|in:every_day,specific_days,once',
            'times' => 'required|array|min:1',
            'times.*' => 'required|date_format:H:i,H:i:s',
            'days' => 'nullable|array',
            'days.*' => 'in:saturday,sunday,monday,tuesday,wednesday,thursday,friday',
        ];
      
    
    }
}
