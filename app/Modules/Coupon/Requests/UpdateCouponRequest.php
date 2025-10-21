<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCouponRequest extends FormRequest
{
    public function rules(): array
    {
        return [
           'code' => [
    'required',
    'string',
    'max:255',
    'unique:coupons,code,' . $this->route('coupon')->id,  
],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'used_count' => ['nullable', 'integer', 'min:0'],
            'once_per_user' => ['nullable', 'boolean'],
        ];
    }
}
