<?php

namespace App\Modules\Delivery\Requests;

use App\Models\DeliveryProvider;
use App\Modules\Delivery\Providers\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryProviderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('delivery_providers', 'code')->ignore($this->route('provider'))],
            'type' => ['required', Rule::in(DeliveryProvider::TYPES)],
            'adapter' => ['required', Rule::in(array_keys(ProviderRegistry::ADAPTERS))],
            // Left empty on edit = keep the stored secret.
            'webhook_secret' => 'nullable|string|min:16|max:255',
            'tracking_url_template' => 'nullable|string|max:255|starts_with:https://,http://',
            'supports_cod' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ];
    }
}
