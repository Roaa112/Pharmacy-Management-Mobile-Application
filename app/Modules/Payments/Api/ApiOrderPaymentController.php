<?php

namespace App\Modules\Payments\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

/** Customer endpoints for an order's online payment. Owner-only. */
class ApiOrderPaymentController extends Controller
{
    /** GET /api/user/orders/{id}/payment — the current payment state (poll after returning from checkout). */
    public function show(Request $request, int $id)
    {
        $order = Order::where('user_id', $request->user()->id)->with('latestPayment')->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => api_trans('orders.not_found')], 404);
        }

        return response()->json(['success' => true, 'order_id' => $order->id, 'payment_status' => $order->payment_status,
            'refund_status' => $order->refund_status, 'payment' => $this->present($order->latestPayment)]);
    }

    /** POST /api/user/orders/{id}/pay — start (or retry) the online payment; returns the checkout URL. */
    public function pay(Request $request, int $id, PaymentService $payments)
    {
        $order = Order::where('user_id', $request->user()->id)->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => api_trans('orders.not_found')], 404);
        }

        try {
            $payment = $payments->startNewAttempt($order, $request->user()->id);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'code' => 'payment_not_possible'], 422);
        }

        $result = $payments->initiate($payment);

        return response()->json(['success' => $result['checkout_url'] !== null, 'order_id' => $order->id, 'payment' => $result],
            $result['checkout_url'] !== null ? 200 : 502);
    }

    private function present(?Payment $payment): ?array
    {
        return $payment ? [
            'reference' => $payment->internal_reference,
            'method' => $payment->provider,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(api_locale()),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ] : null;
    }
}
