<?php

namespace App\Modules\Payments;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Country;
use App\Models\PaymentMethod;
use App\Modules\Payments\Gateways\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payment methods offered at checkout. Orders keep the code they were placed with, so renaming
 * or deactivating a method never changes past orders. A method's code cannot change once used.
 * Active methods are limited to cash on delivery and Paymob.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private GatewayRegistry $gateways)
    {
    }

    public function index()
    {
        $all = PaymentMethod::orderBy('sort_order')->orderBy('id')->get();

        return view('dashboard.payment-methods.index', [
            // Only these can be configured. Retired methods (ZainCash, Qi Card) are listed read-only.
            'methods' => $all->reject(fn (PaymentMethod $m) => $m->isRetired())->values(),
            'retired' => $all->filter(fn (PaymentMethod $m) => $m->isRetired())->values(),
            'retiredOrders' => \App\Models\Order::whereIn('payment_method', $all->filter->isRetired()->pluck('code'))
                ->selectRaw('payment_method, COUNT(*) as total')->groupBy('payment_method')->pluck('total', 'payment_method'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($error = $this->activationError($data['provider'], false, $data['is_active'])) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        $method = PaymentMethod::create($data);
        AdminAuditLog::record('payment_method.create', $method, $method->toArray());

        return redirect()->back()->with('success', 'Payment method created.');
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        // A retired method (e.g. ZainCash) is history only: no field of it can be changed.
        if ($paymentMethod->isRetired()) {
            return redirect()->back()->with('error', 'This payment method is retired and kept for order history only.');
        }

        $data = $this->validated($request, $paymentMethod);

        if ($error = $this->activationError($paymentMethod->provider, $paymentMethod->is_legacy, $data['is_active'])) {
            return redirect()->back()->with('error', $error);
        }

        $paymentMethod->update($data);

        AdminAuditLog::record('payment_method.update', $paymentMethod, $paymentMethod->getChanges());

        return redirect()->back()->with('success', 'Payment method saved.');
    }

    /**
     * Only cash on delivery and Paymob can be offered. Legacy methods (ZainCash, Qi Card) stay
     * for order history and can never be re-activated; Paymob only once it is configured.
     */
    private function activationError(string $provider, bool $legacy, bool $activate): ?string
    {
        if (!$activate) {
            return null;
        }
        if ($legacy || !in_array($provider, PaymentMethod::ACTIVE_PROVIDERS, true)) {
            return 'This payment method is retired and kept for order history only.';
        }
        if ($provider === 'paymob' && ($missing = $this->gateways->for('paymob')->missingConfiguration())) {
            return 'Paymob cannot be activated until it is configured. Missing: '.implode(', ', $missing).'.';
        }

        return null;
    }

    private function validated(Request $request, ?PaymentMethod $method = null): array
    {
        $data = $request->validate([
            'provider' => [$method ? 'prohibited' : 'required', Rule::in(PaymentMethod::ACTIVE_PROVIDERS)],
            'code' => [$method ? 'prohibited' : 'required', 'alpha_dash', 'max:50',
                Rule::unique('payment_methods', 'code')],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:64',
            'instructions_ar' => 'nullable|string|max:2000',
            'instructions_en' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);

        return $data + [
            // Receipt uploads belonged to the retired manual transfers.
            'requires_receipt' => false,
            'country_id' => Country::default()?->id,
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->input('sort_order', 0),
        ];
    }
}
