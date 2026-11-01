<?php

namespace App\Modules\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Paymob (Accept). Implemented from Paymob's developer documentation:
 *  - intention flow: POST {base}/v1/intention/ with "Authorization: Token <secret key>", then the
 *    customer pays on Unified Checkout ({checkout_url}?publicKey=..&clientSecret=..);
 *  - legacy flow: POST /api/auth/tokens (API key) -> /api/ecommerce/orders -> /api/acceptance/payment_keys
 *    -> {base}/api/acceptance/iframes/{iframe_id}?payment_token=..;
 *  - callbacks: HMAC-SHA512 (HMAC secret) over the values of a fixed, lexicographically ordered
 *    list of transaction fields, compared with the "hmac" query parameter;
 *  - inquiry: GET /api/acceptance/transactions/{id} with a bearer token from /api/auth/tokens;
 *  - refunds: POST /api/acceptance/void_refund/refund {transaction_id, amount_cents}.
 *
 * Credentials are read from config('services.paymob.<environment>') only.
 */
class PaymobGateway implements PaymentGateway
{
    /** Transaction fields in the HMAC string, in Paymob's documented order (processed callback paths). */
    private const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
        'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan', 'source_data.sub_type',
        'source_data.type', 'success',
    ];

    public function code(): string
    {
        return Payment::PROVIDER_PAYMOB;
    }

    public function environment(): string
    {
        return config('services.paymob.environment') === 'production' ? 'production' : 'sandbox';
    }

    public function flow(): string
    {
        return config('services.paymob.flow') === 'legacy' ? 'legacy' : 'intention';
    }

    public function missingConfiguration(): array
    {
        $required = $this->flow() === 'legacy'
            ? ['api_key', 'hmac_secret', 'integration_ids', 'iframe_id']
            : ['secret_key', 'public_key', 'hmac_secret', 'integration_ids'];

        if (config('services.paymob.verify_with_api')) {
            $required[] = 'api_key';
        }

        $missing = array_values(array_filter(array_unique($required), fn ($key) => blank($this->credential($key))));

        if (blank(config('services.paymob.base_url'))) {
            $missing[] = 'base_url';
        }

        return array_map(fn ($key) => $this->environment().'.'.$key, $missing);
    }

    public function initiate(Payment $payment, Order $order): PaymentInitiation
    {
        $this->assertConfigured();

        return $this->flow() === 'legacy' ? $this->initiateLegacy($payment, $order) : $this->initiateIntention($payment, $order);
    }

    public function parseNotification(array $query, array $body, bool $isRedirect): GatewayNotification
    {
        // Processed callback: {"type": "TRANSACTION", "obj": {...}}; response callback: flat query string.
        $transaction = $isRedirect ? $this->unflattenRedirect($query) : (array) ($body['obj'] ?? []);
        $hmac = (string) ($query['hmac'] ?? '');

        $valid = $transaction !== [] && $hmac !== '' && !blank($this->credential('hmac_secret'))
            && hash_equals($this->signature($transaction), strtolower($hmac));

        return $this->notificationFrom($transaction, $valid);
    }

    public function fetchTransaction(string $transactionId): ?GatewayNotification
    {
        if (blank($this->credential('api_key'))) {
            return null;
        }

        $response = $this->send(fn () => $this->http()->withToken($this->authToken())->get("/api/acceptance/transactions/{$transactionId}"));

        // Fetched from Paymob over TLS with our own credentials: authentic by construction.
        return $this->notificationFrom((array) $response->json(), true);
    }

    public function refund(Payment $payment, int $amountMinor): GatewayRefundResult
    {
        $this->assertConfigured();

        if (blank($payment->provider_transaction_id)) {
            return new GatewayRefundResult(false, error: 'The payment has no Paymob transaction to refund.');
        }

        $body = ['transaction_id' => $payment->provider_transaction_id, 'amount_cents' => $this->toProviderUnits($amountMinor)];
        $request = $this->http();

        if ($this->flow() === 'legacy') {
            $body['auth_token'] = $this->authToken();
        } else {
            $request = $request->withHeaders(['Authorization' => 'Token '.$this->credential('secret_key')]);
        }

        try {
            $response = $this->send(fn () => $request->post('/api/acceptance/void_refund/refund', $body));
        } catch (PaymentGatewayException $e) {
            return new GatewayRefundResult(false, error: $e->getMessage());
        }

        $data = (array) $response->json();
        $pending = filter_var($data['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $success = filter_var($data['success'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new GatewayRefundResult(
            succeeded: $success && !$pending,
            pending: $pending,
            providerRefundId: isset($data['id']) ? (string) $data['id'] : null,
            error: $success || $pending ? null : (string) (Arr::get($data, 'data.message') ?? 'Refund declined by Paymob.'),
            raw: Arr::except($data, ['source_data', 'payment_key_claims', 'billing_data']),
        );
    }

    /** amount_cents for Paymob from our minor units (see services.paymob.amount_factor). */
    public function toProviderUnits(int $amountMinor): int
    {
        return $amountMinor * max(1, (int) config('services.paymob.amount_factor', 1));
    }

    public function currencyFor(Payment $payment): string
    {
        return strtoupper((string) (config('services.paymob.currency') ?: $payment->currency));
    }

    /** HMAC of a transaction object (exposed for tests and the configuration check). */
    public function signature(array $transaction): string
    {
        $concatenated = '';
        foreach (self::HMAC_FIELDS as $field) {
            $value = Arr::get($transaction, $field);
            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
        }

        return hash_hmac('sha512', $concatenated, (string) $this->credential('hmac_secret'));
    }

    // ------------------------------------------------------------------ flows

    private function initiateIntention(Payment $payment, Order $order): PaymentInitiation
    {
        $amount = $this->toProviderUnits($payment->amountMinor());

        $response = $this->send(fn () => $this->http()
            ->withHeaders(['Authorization' => 'Token '.$this->credential('secret_key')])
            ->post('/v1/intention/', [
                'amount' => $amount,
                'currency' => $this->currencyFor($payment),
                'payment_methods' => $this->integrationIds(),
                // One line for the whole order: Paymob checks that items add up to the amount, and
                // the order total already includes delivery and discounts.
                'items' => [['name' => "Order #{$order->id}", 'amount' => $amount, 'quantity' => 1, 'description' => "Order #{$order->id}"]],
                'billing_data' => $this->billingData($order),
                'special_reference' => $payment->internal_reference,
                'notification_url' => route('payments.webhook', ['provider' => 'paymob']),
                'redirection_url' => route('payments.return', ['provider' => 'paymob']),
                'expiration' => max(60, (int) config('services.paymob.payment_ttl_minutes', 60) * 60),
                'extras' => ['order_id' => $order->id],
            ]));

        $data = (array) $response->json();
        if (blank($data['client_secret'] ?? null)) {
            throw new PaymentGatewayException('Paymob did not return a client secret.');
        }

        $checkout = rtrim((string) config('services.paymob.checkout_url'), '/').'/?'.http_build_query([
            'publicKey' => $this->credential('public_key'),
            'clientSecret' => $data['client_secret'],
        ]);

        return new PaymentInitiation(
            $checkout,
            isset($data['intention_order_id']) ? (string) $data['intention_order_id'] : null,
            ['flow' => 'intention', 'intention_id' => $data['id'] ?? null],
        );
    }

    private function initiateLegacy(Payment $payment, Order $order): PaymentInitiation
    {
        $token = $this->authToken();
        $amount = $this->toProviderUnits($payment->amountMinor());
        $currency = $this->currencyFor($payment);

        $providerOrder = (array) $this->send(fn () => $this->http()->post('/api/ecommerce/orders', [
            'auth_token' => $token, 'delivery_needed' => false, 'amount_cents' => $amount, 'currency' => $currency,
            'merchant_order_id' => $payment->internal_reference, 'items' => [],
        ]))->json();

        if (blank($providerOrder['id'] ?? null)) {
            throw new PaymentGatewayException('Paymob did not create an order.');
        }

        $key = (array) $this->send(fn () => $this->http()->post('/api/acceptance/payment_keys', [
            'auth_token' => $token, 'amount_cents' => $amount, 'currency' => $currency,
            'expiration' => max(60, (int) config('services.paymob.payment_ttl_minutes', 60) * 60),
            'order_id' => $providerOrder['id'], 'billing_data' => $this->billingData($order),
            'integration_id' => $this->integrationIds()[0], 'lock_order_when_paid' => true,
        ]))->json();

        if (blank($key['token'] ?? null)) {
            throw new PaymentGatewayException('Paymob did not issue a payment key.');
        }

        $url = rtrim((string) config('services.paymob.base_url'), '/')."/api/acceptance/iframes/{$this->credential('iframe_id')}?".http_build_query(['payment_token' => $key['token']]);

        return new PaymentInitiation($url, (string) $providerOrder['id'], ['flow' => 'legacy']);
    }

    // ------------------------------------------------------------------ helpers

    private function notificationFrom(array $transaction, bool $signatureValid): GatewayNotification
    {
        $bool = fn ($key) => filter_var(Arr::get($transaction, $key, false), FILTER_VALIDATE_BOOLEAN);
        $id = isset($transaction['id']) ? (string) $transaction['id'] : null;
        $parent = Arr::get($transaction, 'parent_transaction');
        $isRefundTransaction = $bool('has_parent_transaction') && ($bool('is_refund') || $bool('data.is_refund') || $bool('is_refunded'));

        [$outcome, $parentId] = match (true) {
            $bool('is_voided') => [GatewayNotification::VOID, $id],
            $isRefundTransaction && $bool('success') => [GatewayNotification::REFUND, $parent ? (string) $parent : null],
            $bool('is_refunded') && !$bool('has_parent_transaction') => [GatewayNotification::REFUND, $id],
            $bool('pending') => [GatewayNotification::PENDING, null],
            $bool('success') => [GatewayNotification::SUCCESS, null],
            default => [GatewayNotification::FAILED, null],
        };

        return new GatewayNotification(
            signatureValid: $signatureValid,
            outcome: $outcome,
            transactionId: $id,
            providerOrderId: ($orderId = Arr::get($transaction, 'order.id')) !== null ? (string) $orderId : null,
            merchantReference: Arr::get($transaction, 'order.merchant_order_id') ?? Arr::get($transaction, 'special_reference'),
            amountProviderUnits: isset($transaction['amount_cents']) ? (int) $transaction['amount_cents'] : null,
            currency: isset($transaction['currency']) ? strtoupper((string) $transaction['currency']) : null,
            parentTransactionId: $parentId,
            failureReason: $outcome === GatewayNotification::FAILED
                ? (string) (Arr::get($transaction, 'data.message') ?? Arr::get($transaction, 'data.txn_response_code') ?? 'Declined')
                : null,
            // Keep what is needed to audit the decision; never card or billing details.
            raw: Arr::only($transaction, ['id', 'success', 'pending', 'amount_cents', 'currency', 'created_at', 'is_refunded', 'is_voided',
                'has_parent_transaction', 'parent_transaction', 'integration_id', 'error_occured', 'is_3d_secure'])
                + ['order_id' => Arr::get($transaction, 'order.id'), 'merchant_order_id' => Arr::get($transaction, 'order.merchant_order_id'),
                    'source_type' => Arr::get($transaction, 'source_data.type'), 'message' => Arr::get($transaction, 'data.message')],
        );
    }

    /** The response callback arrives as flat query parameters; rebuild the nested shape. */
    private function unflattenRedirect(array $query): array
    {
        $transaction = [];
        foreach ($query as $key => $value) {
            if ($key === 'hmac') {
                continue;
            }
            // PHP turns the dots of "source_data.pan" style parameters into underscores.
            $key = preg_replace('/^source_data_/', 'source_data.', (string) $key);
            Arr::set($transaction, $key, $value);
        }

        // In the response callback the order id is "order" (documented as order_id).
        $orderId = $query['order'] ?? $query['order_id'] ?? null;
        $transaction['order'] = ['id' => $orderId, 'merchant_order_id' => $query['merchant_order_id'] ?? null];

        return $transaction;
    }

    private function billingData(Order $order): array
    {
        $order->loadMissing('user');
        $name = trim((string) ($order->user?->name ?? 'Customer'));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'NA');
        $address = $order->deliveryAddress() ?? [];

        return [
            'first_name' => $first ?: 'NA',
            'last_name' => $last ?: 'NA',
            'email' => $order->user?->email ?: 'NA@example.invalid',
            'phone_number' => $order->user?->phone ?: 'NA',
            'street' => $address['street'] ?? 'NA',
            'building' => $address['building'] ?? 'NA',
            'apartment' => $address['apartment'] ?? 'NA',
            'floor' => 'NA',
            'city' => $address['city'] ?? 'NA',
            'state' => $address['governorate'] ?? 'NA',
            'country' => $order->country?->iso2 ?? 'NA',
        ];
    }

    private function integrationIds(): array
    {
        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->credential('integration_ids'))))));
    }

    private function authToken(): string
    {
        $token = $this->send(fn () => $this->http()->post('/api/auth/tokens', ['api_key' => $this->credential('api_key')]))->json('token');

        if (blank($token)) {
            throw new PaymentGatewayException('Paymob authentication failed.');
        }

        return (string) $token;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.paymob.base_url'), '/'))
            ->acceptJson()->asJson()
            ->timeout((int) config('services.paymob.timeout', 20));
    }

    /** Run a request; failures become PaymentGatewayException with the status only (no secrets). */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Paymob could not be reached.');
        }

        if (!$response->successful()) {
            throw new PaymentGatewayException("Paymob answered HTTP {$response->status()}.");
        }

        return $response;
    }

    private function assertConfigured(): void
    {
        if ($missing = $this->missingConfiguration()) {
            throw new PaymentGatewayException('Paymob is not configured: missing '.implode(', ', $missing).'.');
        }
    }

    private function credential(string $key): ?string
    {
        $value = config("services.paymob.{$this->environment()}.{$key}");

        return blank($value) ? null : (string) $value;
    }
}
