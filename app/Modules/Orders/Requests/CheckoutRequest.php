<?php

namespace App\Modules\Orders\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    private ?PaymentMethod $paymentMethod = null;

    public function rules(): array
    {
        return [
            'address_id' => 'required|integer', // -1 = pickup from the pharmacy
            'payment_method' => ['required', 'string', Rule::in($this->availableMethods()->pluck('code')->all())],
            'payment_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'notes' => 'nullable|string|max:225',
            'coupon_code' => 'nullable|string|max:255',
            // Optional: the total the app showed. When sent and different from what checkout
            // calculates now (a promotion ended, a price changed), nothing is ordered (409).
            'expected_total' => 'nullable|numeric|min:0',
            // Optional: loyalty points to pay part of the order with (rules: Loyalty › Settings).
            'redeem_points' => 'nullable|integer|min:1|max:100000000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $method = $this->paymentMethod();

            if ($method?->requires_receipt && !$this->hasFile('payment_image')) {
                $validator->errors()->add('payment_image', __('orders.receipt_required', [], api_locale()));
            }
        });
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod ??= $this->availableMethods()->firstWhere('code', $this->input('payment_method'));
    }

    private $methods = null;

    private function availableMethods()
    {
        // Offered = active, not legacy, and (for Paymob) configured. ZainCash / Qi Card are legacy.
        return $this->methods ??= PaymentMethod::offered()->get()
            ->filter(fn (PaymentMethod $method) => app(\App\Modules\Payments\Services\PaymentService::class)->providerReady($method->provider))
            ->values();
    }
}
