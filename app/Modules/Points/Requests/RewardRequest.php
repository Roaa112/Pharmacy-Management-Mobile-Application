<?php

namespace App\Modules\Points\Requests;

use App\Models\LoyaltyReward;
use App\Support\MarketingImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RewardRequest extends FormRequest
{
    public function rules(): array
    {
        $reward = $this->route('reward');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('loyalty_rewards', 'code')->ignore($reward?->id)],
            'type' => ['required', Rule::in(LoyaltyReward::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', ...MarketingImage::RULES],
            'product_size_id' => ['nullable', 'integer', Rule::exists('product_sizes', 'id')],
            'points_required' => ['required', 'integer', 'min:1', 'max:10000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'total_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') === LoyaltyReward::TYPE_PRODUCT && !$this->filled('product_size_id')) {
                $validator->errors()->add('product_size_id', 'Choose the product size this reward gives.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'sort_order' => $this->input('sort_order') ?? 0]);
    }
}
