<?php

namespace App\Modules\Coupon\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCouponRequest extends FormRequest
{
    protected function codeRule(): array
    {
        // Archived coupons keep their code: a code is never reused for a different offer.
        return ['required', 'string', 'max:255', Rule::unique('coupons', 'code')];
    }

    public function rules(): array
    {
        return [
            'code' => $this->codeRule(),
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:percent,fixed'],
            // A percentage (0-100) or, for a fixed coupon, an amount; always positive.
            'discount_value' => array_filter(['required', 'numeric', 'gt:0', $this->input('type', 'percent') === 'fixed' ? 'max:99999999' : 'max:100']),
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'applies_to_delivery' => ['nullable', 'in:,0,1'],
            'is_active' => ['nullable', 'boolean'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'once_per_user' => ['nullable', 'boolean'],
            'first_order_only' => ['nullable', 'boolean'],
            'combines_with_promotions' => ['nullable', 'boolean'],
            'customer_scope' => ['nullable', 'in:all,specific'],
            'customer_emails' => ['nullable', 'string', 'max:20000'],
            'target_categories' => ['nullable', 'array'],
            'target_categories.*' => ['integer', 'exists:categories,id'],
            'target_brands' => ['nullable', 'array'],
            'target_brands.*' => ['integer', 'exists:brands,id'],
            'target_products' => ['nullable', 'array'],
            'target_products.*' => ['integer', 'exists:products,id'],
            'excluded_products' => ['nullable', 'array'],
            'excluded_products.*' => ['integer', 'exists:products,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('customer_scope') === 'specific' && !trim((string) $this->input('customer_emails'))) {
                $validator->errors()->add('customer_emails', 'List the customers who may use this coupon.');
            }
            if ($this->input('end_at') && $this->input('start_at') && $this->isMethod('post') && \Illuminate\Support\Carbon::parse($this->input('end_at'))->endOfDay()->isPast()) {
                $validator->errors()->add('end_at', 'The coupon would already be expired.');
            }
        });
    }
}
