<?php

namespace App\Modules\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fee' => 'required|numeric|min:0|max:99999999',
            'free_over_amount' => 'nullable|numeric|min:0|max:99999999',
            'min_order_amount' => 'nullable|numeric|min:0|max:99999999',
            'eta_min_days' => 'nullable|integer|min:0|max:365',
            'eta_max_days' => 'nullable|integer|min:0|max:365|gte:eta_min_days',
            'priority' => 'nullable|integer|min:-1000|max:1000',
            'delivery_provider_id' => 'nullable|integer|exists:delivery_providers,id',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ];
    }
}
