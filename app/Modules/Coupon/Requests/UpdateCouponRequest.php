<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Validation\Rule;

class UpdateCouponRequest extends StoreCouponRequest
{
    protected function codeRule(): array
    {
        return ['required', 'string', 'max:255', Rule::unique('coupons', 'code')->ignore($this->route('coupon')->id)];
    }
}
