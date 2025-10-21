<?php

namespace App\Modules\Discount\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscountRequest extends FormRequest
{
    public function rules(): array
    {
        return (new StoreDiscountRequest)->rules();
    }
}
