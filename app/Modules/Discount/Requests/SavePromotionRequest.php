<?php

namespace App\Modules\Discount\Requests;

use App\Models\DiscountRule;
use App\Models\DiscountRuleTarget;
use App\Models\PromotionReward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dashboard form rules for every promotion type (first validation level). The domain rules
 * (conflicts, rewards that exist, activation blocking) are in PromotionValidator.
 */
class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Rows the form leaves empty (no id / no reward type) are not part of the promotion.
        foreach (['targets' => 'id', 'rewards' => 'reward_type'] as $list => $required) {
            if ($this->has($list)) {
                $this->merge([$list => array_values(array_filter((array) $this->input($list, []), fn ($row) => is_array($row) && !empty($row[$required])))]);
            }
        }
    }

    public function rules(): array
    {
        $type = $this->input('discount_type', $this->route('discount') ? DiscountRule::withTrashed()->find($this->route('discount'))?->discount_type : null);

        return [
            'discount_type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(DiscountRule::TYPES)],
            'name_en' => 'nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description_en' => 'nullable|string|max:2000',
            'description_ar' => 'nullable|string|max:2000',
            'campaign_id' => 'nullable|integer|exists:promotion_campaigns,id',
            'discount_value' => array_filter(['nullable', Rule::requiredIf(in_array($type, DiscountRule::ITEM_TYPES, true)), 'numeric', 'gt:0',
                $type === DiscountRule::TYPE_PERCENT ? 'max:100' : 'max:99999999']),
            'min_quantity' => ['nullable', Rule::requiredIf($type === DiscountRule::TYPE_BUY_X_GET_Y), 'integer', 'min:1', 'max:1000'],
            'min_amount' => ['nullable', Rule::requiredIf($type === DiscountRule::TYPE_FREE_DELIVERY), 'numeric', 'min:0', 'max:99999999'],
            'targets' => ['array', Rule::requiredIf(in_array($type, [DiscountRule::TYPE_PERCENT, DiscountRule::TYPE_FIXED, DiscountRule::TYPE_BUY_X_GET_Y], true))],
            'targets.*.type' => ['required', Rule::in(DiscountRuleTarget::TYPES)],
            'targets.*.id' => 'required|integer|min:1',
            'targets.*.excluded' => 'nullable|boolean',
            'gift_targets' => 'nullable|array',
            'gift_targets.*.type' => 'required|in:product,brand,category',
            'gift_targets.*.id' => 'required|integer|min:1',
            'rewards' => 'array',
            'rewards.*.id' => 'nullable|integer',
            'rewards.*.reward_type' => ['required', Rule::in(PromotionReward::TYPES)],
            'rewards.*.threshold_amount' => 'nullable|numeric|gt:0|max:99999999',
            'rewards.*.product_id' => 'nullable|required_if:rewards.*.reward_type,free_product|integer|exists:products,id',
            'rewards.*.product_size_id' => 'nullable|integer|exists:product_sizes,id',
            'rewards.*.quantity' => 'nullable|integer|min:1|max:100',
            'rewards.*.value' => 'nullable|required_if:rewards.*.reward_type,fixed_discount,percent_discount|numeric|gt:0|max:99999999',
            'rewards.*.max_discount_amount' => 'nullable|numeric|gt:0|max:99999999',
            // A promotion without dates never becomes active (pricing and checkout filter on them).
            'starts_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date', 'after:starts_at'],
            'is_active' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:-1000|max:1000',
            'is_exclusive' => 'nullable|boolean',
            'is_stackable' => 'nullable|boolean',
            'repeatable' => 'nullable|boolean',
            'max_applications_per_order' => 'nullable|integer|min:1|max:1000',
            'usage_limit' => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1|max:1000',
            'customer_scope' => ['nullable', Rule::in([DiscountRule::SCOPE_ALL, DiscountRule::SCOPE_NEW, DiscountRule::SCOPE_RETURNING, DiscountRule::SCOPE_SPECIFIC])],
            'customer_emails' => 'nullable|string|max:20000',
            'min_order_amount' => 'nullable|numeric|min:0|max:99999999',
            'max_order_amount' => 'nullable|numeric|gt:0|max:99999999',
            'max_discount_amount' => 'nullable|numeric|gt:0|max:99999999',
            'qualifying_basis' => 'nullable|in:discounted,original',
            'count_delivery_fee' => 'nullable|boolean',
            'coupon_reduces_qualifying' => 'nullable|boolean',
            'tier_mode' => ['nullable', Rule::in([DiscountRule::TIER_HIGHEST, DiscountRule::TIER_CUMULATIVE])],
            'gift_fallback' => ['nullable', Rule::in([DiscountRule::FALLBACK_PARTIAL, DiscountRule::FALLBACK_SKIP, DiscountRule::FALLBACK_BLOCK])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('discount_type');
            if (in_array($type, DiscountRule::GIFT_TYPES, true) && !$this->input('rewards') && !$this->input('gift_targets')) {
                $validator->errors()->add('rewards', 'Add at least one reward.');
            }
            if ($type === DiscountRule::TYPE_SPEND_X_GET_Y) {
                foreach ((array) $this->input('rewards', []) as $i => $reward) {
                    if (empty($reward['threshold_amount']) && !$this->filled('min_amount')) {
                        $validator->errors()->add("rewards.{$i}.threshold_amount", 'Each reward needs a minimum spend (or set the promotion minimum).');
                    }
                }
            }
            foreach ((array) $this->input('rewards', []) as $i => $reward) {
                if (($reward['reward_type'] ?? null) === PromotionReward::PERCENT_DISCOUNT && isset($reward['value']) && (float) $reward['value'] > 100) {
                    $validator->errors()->add("rewards.{$i}.value", 'A percentage is at most 100.');
                }
                if (!empty($reward['product_size_id']) && !empty($reward['product_id'])
                    && !\App\Models\ProductSize::whereKey($reward['product_size_id'])->where('product_id', $reward['product_id'])->exists()) {
                    $validator->errors()->add("rewards.{$i}.product_size_id", 'The variant does not belong to the product.');
                }
            }
        });
    }

    /** Validated data with checkbox booleans normalised. */
    public function promotionData(): array
    {
        $data = $this->validated();

        foreach (['is_active', 'is_exclusive', 'is_stackable', 'repeatable', 'count_delivery_fee', 'coupon_reduces_qualifying'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
        }
        foreach (['targets', 'rewards'] as $list) {
            if (!$this->has($list) && !$this->isMethod('post')) {
                unset($data[$list]);
            }
        }
        if (!in_array($data['discount_type'] ?? null, DiscountRule::GIFT_TYPES, true)) {
            unset($data['gift_targets']);
        }

        return $data;
    }
}
