<?php

namespace App\Modules\Points\Requests;

use App\Modules\Points\Services\LoyaltySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltySettingsRequest extends FormRequest
{
    private const BOOLEANS = [
        'loyalty_enabled', 'points_never_expire', 'loyalty_earn_on_shipping', 'loyalty_discounts_reduce_points', 'loyalty_earn_on_cod',
        'loyalty_combine_with_discounts', 'loyalty_allow_partial_redemption', 'loyalty_allow_multiple_redemptions',
    ];

    public function rules(): array
    {
        return [
            // Earning: get_y points per spend_x.
            'spend_x' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'get_y' => ['required', 'integer', 'min:0', 'max:100000'],
            'loyalty_min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'loyalty_max_points_per_order' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'loyalty_earn_basis' => ['required', Rule::in(LoyaltySettings::BASES)],
            'loyalty_activation' => ['required', Rule::in(LoyaltySettings::ACTIVATIONS)],
            // Expiry
            'points_validity_days' => ['required_unless:points_never_expire,1', 'nullable', 'integer', 'min:0', 'max:3650'],
            'loyalty_expiry_warning_days' => ['required', 'integer', 'min:1', 'max:365'],
            // Redemption
            'loyalty_point_value' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'loyalty_min_redeem_points' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'loyalty_max_redeem_points_per_order' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'loyalty_redemption_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ] + array_fill_keys(self::BOOLEANS, ['nullable', 'boolean']);
    }

    /** Validated values, unticked checkboxes as false and empty limits as "no limit". */
    public function settings(): array
    {
        $data = $this->validated();

        foreach (self::BOOLEANS as $key) {
            $data[$key] = $this->boolean($key);
        }

        $data['loyalty_min_order_amount'] ??= 0;
        $data['loyalty_point_value'] ??= 0;
        $data['loyalty_min_redeem_points'] ??= 0;
        $data['points_validity_days'] ??= $data['points_never_expire'] ? null : 0;
        foreach (['loyalty_max_points_per_order', 'loyalty_max_redeem_points_per_order', 'loyalty_redemption_validity_days'] as $limit) {
            $data[$limit] = $data[$limit] ?? null;
        }
        if ($data['points_validity_days'] === null) {
            unset($data['points_validity_days']); // never expire: keep the last period for when it is switched back
        }

        return $data;
    }
}
