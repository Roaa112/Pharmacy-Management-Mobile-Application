<?php

namespace App\Modules\Payments\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider callbacks. Authenticated by the provider's signature (HMAC), never by a user token.
 *
 *  POST /api/webhooks/payments/{provider}  server-to-server notification (the source of truth)
 *  GET  /payments/{provider}/return         the customer's browser coming back from checkout
 *
 * Responses: 200 processed / duplicate / ignored; 400 bad signature (nothing applied);
 * 500 only when our own processing failed, so the provider retries.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $provider, PaymentService $payments, GatewayRegistry $gateways)
    {
        abort_unless($gateways->has($provider) && $provider !== 'cod', 404);

        try {
            $event = $payments->handleNotification($provider, $request->query(), $request->json()->all() ?: $request->all(), false);
        } catch (\Throwable $e) {
            Log::error('Payment webhook processing failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json(['status' => 'error'], 500);
        }

        // Another worker is applying this very notification: ask the provider to retry later (if that
        // worker dies, the retry or payments:recover-events claims the event again).
        if ($event->getAttribute('was_duplicate') && $event->isInProgress()) {
            return response()->json(['status' => 'in_progress'], 503)->header('Retry-After', '60');
        }

        if ($event->result === PaymentEvent::RESULT_REJECTED && !$event->signature_valid) {
            return response()->json(['status' => 'invalid_signature'], 400);
        }

        return response()->json(['status' => $event->getAttribute('was_duplicate') ? 'duplicate' : $event->result]);
    }

    /**
     * The customer returns from the payment page. The query is signed like the webhook; a valid
     * one is applied through the same path (idempotent with the webhook). The customer is sent
     * back to the app with the result; nothing here trusts unsigned parameters.
     */
    public function return(Request $request, string $provider, PaymentService $payments, GatewayRegistry $gateways)
    {
        abort_unless($gateways->has($provider) && $provider !== 'cod', 404);

        $status = 'failed';
        $reference = null;
        $event = null;

        try {
            $event = $payments->handleNotification($provider, $request->query(), [], true);
            if ($event->signature_valid) {
                $payment = $event->payment;
                $reference = $payment?->internal_reference;
                $status = match (true) {
                    $payment?->status->isSettledPayment() => 'success',
                    $payment?->status->value === 'pending' => 'pending',
                    default => 'failed',
                };
            }
        } catch (\Throwable $e) {
            Log::warning('Payment return processing failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            $status = 'pending'; // the webhook will settle it
        }

        $target = (string) config('services.paymob.return_url');
        $query = http_build_query(array_filter(['status' => $status, 'reference' => $reference, 'order_id' => $event?->payment?->order_id]));

        return $target !== ''
            ? redirect()->away($target.(str_contains($target, '?') ? '&' : '?').$query)
            : response()->json(['status' => $status, 'reference' => $reference]);
    }
}
