<?php

namespace App\Modules\FlashSale\Requests;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreFlashSaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'discount_value' => 'required|numeric|min:0.01', 
            'is_active' => 'required|boolean', 
            'date' => 'required|date|after_or_equal:today', 
            'time' => 'required|date_format:H:i', 
        ];
    }
}

