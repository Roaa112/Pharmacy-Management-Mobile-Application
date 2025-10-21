<?php

namespace App\Modules\Discount\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'discount_type'     => 'required|in:fixed,percent,buy_x_get_y,amount_gift',
            'discount_value'    => 'nullable|numeric|min:0',
            'min_amount'        => 'nullable|numeric|min:0',
            'min_quantity'      => 'nullable|integer|min:1',
            'free_quantity'     => 'nullable|integer|min:1',

            'applies_to_type'   => 'required|in:product,brand,category',
            'applies_to_id'     => 'required|integer|exists:' . $this->resolveTable('applies_to_type') . ',id',

            'gift_type'         => 'nullable|in:product,brand,category',
            'gift_id'           => 'nullable|integer',
            'gift_quantity'     => 'nullable|integer|min:1',

            'starts_at'         => 'nullable|date',
            'ends_at'           => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    private function resolveTable(string $field): string
    {
        $mapping = [
            'product'  => 'products',
            'brand'    => 'brands',
            'category' => 'categories',
        ];

        return $mapping[$this->input($field, '')] ?? 'products';
    }
}
