<?php

/*
 * One worker process of tests/Feature/Concurrency/ConcurrencyTest. Usage:
 *
 *   php tests/Concurrency/worker.php <scenario> <start-at unix time> <json arguments>
 *
 * Boots the application (database settings come from the environment the test passes), does
 * its preparation, waits for the shared start instant so all workers hit the database at once,
 * runs one real production code path, and prints one JSON line with the outcome.
 */

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Inventory\Services\PurchasingService;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Support\Facades\DB;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $scenario, $startAt, $json] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

if (isset($args['paymob'])) {
    config(['services.paymob' => array_replace_recursive(config('services.paymob'), $args['paymob'])]);
}

DB::connection()->getPdo(); // connected before the barrier

$run = match ($scenario) {
    'checkout' => function () use ($args) {
        $response = app(CheckoutService::class)->checkout(User::findOrFail($args['user_id']),
            ['address_id' => -1, 'payment_method' => 'cash'] + (isset($args['coupon']) ? ['coupon_code' => $args['coupon']] : []),
            PaymentMethod::where('code', 'cash')->firstOrFail(), null);

        return ['status' => $response->getStatusCode(), 'total' => $response->getData(true)['total_price'] ?? null, 'code' => $response->getData(true)['code'] ?? null];
    },
    'webhook' => function () use ($args) {
        $payment = Payment::findOrFail($args['payment_id']);
        $tx = [
            'id' => $args['transaction_id'], 'amount_cents' => $payment->amountMinor(), 'created_at' => now()->toIso8601String(), 'currency' => 'EGP',
            'error_occured' => false, 'has_parent_transaction' => false, 'integration_id' => 1, 'is_3d_secure' => true, 'is_auth' => false,
            'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'], 'success' => true,
            'order' => ['id' => (int) $payment->provider_order_id, 'merchant_order_id' => $payment->internal_reference],
        ];
        $event = app(PaymentService::class)->handleNotification('paymob', ['hmac' => app(PaymobGateway::class)->signature($tx)], ['obj' => $tx], false);

        return ['status' => $event->getAttribute('was_duplicate') ? 'duplicate' : $event->result];
    },
    'refund_request' => fn () => ['status' => app(RefundService::class)->requestForPayment(Payment::findOrFail($args['payment_id']), $args['amount_minor'], 'Concurrent request', null) ? 'created' : 'none'],
    'supplier_payment' => fn () => ['status' => app(PurchasingService::class)->recordSupplierPayment(Supplier::findOrFail($args['supplier_id']),
        ['supplier_invoice_id' => $args['invoice_id'], 'amount' => $args['amount'], 'paid_on' => now()->toDateString(), 'request_key' => $args['request_key']], null)->wasRecentlyCreated ? 'created' : 'existing'],
    'otp' => function () use ($app, $args) {
        $request = Illuminate\Http\Request::create('/api/user/password/verify-otp', 'POST', ['email' => $args['email'], 'otp_code' => $args['code']], [], [], ['REMOTE_ADDR' => $args['ip'], 'HTTP_ACCEPT' => 'application/json']);

        return ['status' => $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request)->getStatusCode(), 'code' => $args['code']];
    },
    'set_stock' => function () use ($args) {
        App\Models\ProductSize::setStock($args['size_id'], $args['stock'], App\Models\StockMovement::TYPE_ADJUSTMENT, ['reason' => 'Concurrent admin edit']);

        return ['status' => 'set'];
    },
    'cancel' => function () use ($args) {
        app(OrderWorkflow::class)->transition(Order::findOrFail($args['order_id']), App\Enums\OrderStatus::Canceled, OrderWorkflow::ACTOR_ADMIN, null);

        return ['status' => 'canceled'];
    },
    'deliver' => function () use ($args) {
        $shipment = Shipment::findOrFail($args['shipment_id']);
        app(OrderFulfilment::class)->record($shipment, App\Enums\ShipmentStatus::Delivered, 'delivered', 'admin', null, now(), [], "concurrency:{$shipment->id}:delivered");

        return ['status' => 'delivered'];
    },
    default => throw new InvalidArgumentException("Unknown scenario {$scenario}"),
};

while (microtime(true) < (float) $startAt) {
    usleep(300);
}

try {
    $result = $run();
} catch (DomainException $e) {
    $result = ['status' => 'refused', 'message' => mb_substr($e->getMessage(), 0, 200)];
} catch (Throwable $e) {
    $result = ['status' => 'exception', 'class' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)];
}

echo json_encode($result), "\n";
