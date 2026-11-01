<?php

namespace App\Modules\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;

class ApiPaymentMethodController extends Controller
{
    /**
     * Methods checkout accepts now; "code" is what checkout's payment_method expects.
     * Cash on delivery and Paymob only (Paymob once configured). The account fields are kept,
     * empty, for app versions that still read them.
     */
    public function index(PaymentService $payments)
    {
        $methods = PaymentMethod::offered()->get()
            ->filter(fn (PaymentMethod $m) => $payments->providerReady($m->provider))
            ->values();

        return response()->json(['data' => $methods->map(fn (PaymentMethod $m) => [
            'code' => $m->code,
            'type' => $m->provider === 'paymob' ? 'online' : 'cash_on_delivery',
            'name' => $m->name,
            'requires_receipt' => $m->requires_receipt,
            'account_name' => $m->account_name,
            'account_number' => $m->account_number,
            'instructions' => app()->getLocale() === 'ar' ? $m->instructions_ar : $m->instructions_en,
        ])]);
    }
}
