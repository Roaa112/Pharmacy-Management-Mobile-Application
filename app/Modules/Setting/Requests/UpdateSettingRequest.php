<?php

namespace App\Modules\Setting\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every settings value is validated by type and range. Loyalty values are stored in the
 * existing columns (spend_x / get_y, which the app reads) but must be numbers.
 */
class UpdateSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'privacy_policy_ar' => ['nullable', 'string', 'max:60000'],
            'privacy_policy_en' => ['nullable', 'string', 'max:60000'],
            'terms_conditions_ar' => ['nullable', 'string', 'max:60000'],
            'terms_conditions_en' => ['nullable', 'string', 'max:60000'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'tiktok' => ['nullable', 'url', 'max:255'],
            'youtube' => ['nullable', 'url', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'map_location_url' => ['nullable', 'url', 'max:255'],
            // Loyalty: customers earn get_y points per spend_x of products.
            'spend_x' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'get_y' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'points_validity_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            // Store policies.
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'return_window_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'customer_can_cancel_pending' => ['nullable', 'boolean'],
            'coupon_applies_to_delivery' => ['nullable', 'boolean'],
            'coupon_release_on_cancel' => ['nullable', 'boolean'],
            'vaccine_reminders_enabled' => ['nullable', 'boolean'],
            'medication_reminders_enabled' => ['nullable', 'boolean'],
        ];
    }
}
