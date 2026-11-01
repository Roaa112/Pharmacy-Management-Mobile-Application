<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\Address;
use App\Models\Coupon;
use App\Enums\OrderStatus;
use App\Models\Country;
use App\Models\DeliveryProvider;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\ProviderStatusMap;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Modules\Delivery\Services\DeliveryQuoteService;
use App\Modules\Points\Services\LoyaltyService;
use App\Models\DiscountRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payments\Services\PaymentService;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Orders in every status, priced the way checkout prices them: delivery fee by governorate,
 * loyalty points on the products subtotal, coupon percentage on subtotal + delivery. Stock
 * leaves through the ledger (one "sale" row per line) and canceled orders put it back.
 */
class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $orders = [
            // email, lines [product, size index, qty], status, days ago, payment, coupon, points status
            ['sara@example.com', [['Paracetamol 500 mg', 0, 2], ['Vitamin C 1000 mg Effervescent', 0, 1]], 'completed', 45, 'cash', null, 'earned'],
            ['sara@example.com', [['Intense Moisturizing Cream', 0, 1], ['Sunscreen SPF 50+', 0, 1]], 'completed', 12, 'cash', 'WELCOME10', 'earned'],
            ['sara@example.com', [['Prenatal Multivitamin', 0, 1]], 'on_delivery', 1, 'cash', null, 'earned'],
            ['omar@example.com', [['Ibuprofen 400 mg', 0, 1], ['Omega-3 Fish Oil', 0, 1]], 'completed', 20, 'cash', null, 'redeemed'],
            ['omar@example.com', [['Honey Cough Syrup', 1, 1]], 'canceled', 6, 'cash', null, 'earned'],
            ['omar@example.com', [['Vitamin D3 5000 IU', 0, 2]], 'pending', 0, 'cash', null, 'earned'],
            ['mona@example.com', [['No-Tears Baby Shampoo', 0, 2], ['Baby Diaper Cream', 0, 1]], 'completed', 15, 'cash', null, 'earned'],
            ['mona@example.com', [['Joint Relief Gel', 1, 1], ['No-Tears Baby Shampoo', 1, 1]], 'confirmed', 2, 'cash', 'HEALTH20', 'earned'],
            ['youssef@example.com', [['Blood Glucose Meter Kit', 0, 1], ['Glucose Test Strips', 0, 2]], 'completed', 25, 'cash', 'VIP50', 'earned'],
            ['youssef@example.com', [['Digital Blood Pressure Monitor', 0, 1]], 'completed', 8, 'cash', null, 'earned'],
            ['youssef@example.com', [['Glucose Test Strips', 1, 1]], 'pending', 0, 'cash', null, 'earned'],
            ['nour@example.com', [['Antiseptic Mouthwash', 0, 1]], 'canceled', 3, 'cash', null, 'earned'],
        ];

        $this->seedCourier();

        foreach ($orders as [$email, $lines, $status, $daysAgo, $payment, $couponCode, $pointsStatus]) {
            $this->placeOrder(User::where('email', $email)->firstOrFail(), $lines, $status, $daysAgo, $payment, $couponCode, $pointsStatus);
        }

        $this->orderForADeletedProduct();
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $lines
     */
    private function placeOrder(User $user, array $lines, string $status, int $daysAgo, string $payment, ?string $couponCode, string $pointsStatus): Order
    {
        // Created "at" that time, so the order, its items and its ledger rows share one date.
        return $this->at(now()->subDays($daysAgo)->setTime(10 + $daysAgo % 8, 15), function () use ($user, $lines, $status, $payment, $couponCode, $pointsStatus) {
            return DB::transaction(function () use ($user, $lines, $status, $payment, $couponCode, $pointsStatus) {
                $address = Address::where('user_id', $user->id)->orderByDesc('is_default')->first();
                $deliveryFee = $address ? Money::fromMinor(app(DeliveryQuoteService::class)->quote($address, 0)->feeMinor) : 0;

                $items = collect($lines)->map(function ($line) {
                    [$name, $sizeIndex, $quantity] = $line;
                    $product = Product::where('name_en', $name)->firstOrFail();
                    $size = $product->sizes()->orderBy('id')->get()[$sizeIndex];

                    return compact('product', 'size', 'quantity');
                });

                $subtotalMinor = $items->sum(fn ($i) => Money::toMinor($i['size']->price) * $i['quantity']);
                $totalMinor = $subtotalMinor + Money::toMinor($deliveryFee);

                $settings = Setting::current();
                $spendXMinor = Money::toMinor($settings?->spend_x);
                $points = $spendXMinor > 0 ? intdiv($subtotalMinor, $spendXMinor) * (int) $settings->get_y : 0;

                $coupon = $couponCode ? Coupon::where('code', $couponCode)->firstOrFail() : null;
                $discountMinor = $coupon ? min(Money::percentOf($totalMinor, $coupon->discount_value), $totalMinor) : 0;
                $totalMinor -= $discountMinor;
                $country = Country::default();

                $gift = $this->giftFor($items);

                $order = Order::create([
                    'user_id' => $user->id,
                    'address_id' => $address?->id,
                    'payment_method' => $payment,
                    'payment_image' => $payment === 'cash' ? null : 'payment_images/demo-transfer.png',
                    'delivery_fee' => $deliveryFee,
                    'total_price' => Money::fromMinor($totalMinor),
                    'gift_description' => $gift?->description,
                    'gift_rule_id' => $gift?->id,
                    'coupon_id' => $coupon?->id,
                    'points_earned' => $points,
                    'subtotal' => Money::fromMinor($subtotalMinor),
                    'discount_amount' => Money::fromMinor($discountMinor),
                    'shipping_address' => $address?->snapshot(),
                    'country_id' => $country?->id,
                    'currency_code' => $country?->currency_code,
                    'payment_method_id' => PaymentMethod::where('code', $payment)->value('id'),
                    'payment_status' => match (true) {
                        $status === 'completed' => 'paid',
                        $payment !== 'cash' && $status !== 'canceled' => 'awaiting_verification',
                        default => 'unpaid',
                    },
                ]);

                foreach ($items as ['product' => $product, 'size' => $size, 'quantity' => $quantity]) {
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_size_id' => $size->id,
                        'quantity' => $quantity,
                        'price_at_time' => $size->price,
                        'original_price' => $size->price,
                        'total_price' => Money::fromMinor(Money::toMinor($size->price) * $quantity),
                        'product_name_ar' => $product->name_ar,
                        'product_name_en' => $product->name_en,
                        'size_label' => $size->size,
                    ]);

                    app(InventoryService::class)->reserve($orderItem, ['user_id' => $user->id]);
                }

                if ($coupon) {
                    $coupon->increment('used_count');
                    $coupon->users()->attach($user->id);
                }

                // The same stock path as the workflow: dispatched orders fulfil, canceled ones release.
                foreach ($order->items as $item) {
                    if (OrderStatus::from($status)->isDispatched()) {
                        app(InventoryService::class)->fulfil($item, ['admin_id' => Admin::value('id')]);
                    } elseif ($status === 'canceled') {
                        app(InventoryService::class)->releaseForCancellation($item, ['admin_id' => Admin::value('id')]);
                    }
                }

                $order->forceFill([
                    'status' => $status,
                    'points_status' => $pointsStatus,
                    'completed_at' => $status === 'completed' ? now() : null,
                    'canceled_at' => $status === 'canceled' ? now() : null,
                ])->save();

                $this->seedFulfilment($order, $address, $status);

                // The payment record, as checkout creates it; delivered/completed cash is collected.
                $payments = app(PaymentService::class);
                $payment = $payments->createForCheckout($order, PaymentMethod::where('code', $payment)->firstOrFail());
                if ($status === 'completed') {
                    $payments->recordCodCollection($payment, $payment->amountMinor(), $address ? 'courier' : 'counter', PaymentService::ACTOR_SYSTEM, null, 'Collected on delivery');
                } elseif ($status === 'canceled') {
                    $payments->onOrderCanceled($order, PaymentService::ACTOR_ADMIN, Admin::value('id'));
                }

                return $order;
            });
        });
    }

    /**
     * The gift rule checkout would attach (buy-x-get-y on a product target), with its description.
     */
    /** Status history, a shipment with its events, and ledger rows, as checkout and the workflow write them. */
    private function seedFulfilment(Order $order, ?Address $address, string $status): void
    {
        $steps = $status === 'canceled' ? ['pending', 'canceled'] : array_slice(OrderStatus::FLOW, 0, array_search($status, OrderStatus::FLOW, true) + 1);
        $from = null;
        foreach ($steps as $step) {
            OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $step, 'actor_type' => $from ? 'admin' : 'customer', 'actor_id' => $from ? null : $order->user_id]);
            $from = $step;
        }

        app(LoyaltyService::class)->recordEarn($order);

        if (!$address) {
            return;
        }

        $courier = DeliveryProvider::where('code', 'fast-courier')->first();
        $shipmentStatus = ['pending' => 'pending', 'confirmed' => 'assigned', 'on_delivery' => 'in_transit', 'completed' => 'delivered', 'canceled' => 'canceled'][$status];
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'delivery_provider_id' => $shipmentStatus === 'pending' ? DeliveryProvider::default()?->id : $courier?->id,
            'status' => $shipmentStatus,
            'tracking_number' => $shipmentStatus === 'pending' ? null : 'FC'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'fee_charged' => $order->delivery_fee,
            'cod_amount' => $order->payment_method === 'cash' ? $order->total_price : 0,
            'cod_collected_at' => $shipmentStatus === 'delivered' && $order->payment_method === 'cash' ? now() : null,
            'recipient_name' => $order->user?->name,
            'recipient_phone' => $order->user?->phone,
            'address_snapshot' => $address->snapshot(),
            'delivered_at' => $shipmentStatus === 'delivered' ? now() : null,
        ]);

        foreach (array_unique(['pending', $shipmentStatus]) as $i => $eventStatus) {
            ShipmentEvent::create([
                'shipment_id' => $shipment->id, 'status' => $eventStatus, 'source' => $i ? ShipmentEvent::SOURCE_WEBHOOK : ShipmentEvent::SOURCE_SYSTEM,
                'raw_status' => $i ? strtoupper($eventStatus) : null, 'occurred_at' => now(), 'idempotency_key' => "demo:{$shipment->id}:{$eventStatus}", 'applied' => true,
            ]);
        }
    }

    /** An external courier with its own status vocabulary (mapped to ours). */
    private function seedCourier(): void
    {
        $courier = DeliveryProvider::firstOrCreate(['code' => 'fast-courier'], [
            'name' => 'Fast Courier (demo)', 'type' => DeliveryProvider::TYPE_EXTERNAL_API, 'adapter' => 'signed_webhook',
            'webhook_secret' => 'demo-webhook-secret-change-me', 'tracking_url_template' => 'https://track.example.com/{tracking}',
            'supports_cod' => true, 'is_active' => true, 'country_id' => Country::default()?->id,
        ]);

        foreach (['ASSIGNED' => 'assigned', 'PICKED' => 'picked_up', 'IN_TRANSIT' => 'in_transit', 'DELIVERED' => 'delivered', 'FAILED_ATTEMPT' => 'failed', 'RTO' => 'returned'] as $external => $internal) {
            ProviderStatusMap::firstOrCreate(['delivery_provider_id' => $courier->id, 'external_status' => $external], ['internal_status' => $internal]);
        }
    }

    private function giftFor($items): ?DiscountRule
    {
        $rule = DiscountRule::with('targets')->where('discount_type', 'buy_x_get_y')->first();
        $target = $rule?->targets->firstWhere('is_gift', false);
        $giftTarget = $rule?->targets->firstWhere('is_gift', true);

        if (!$rule || !$target || !$giftTarget) {
            return null;
        }

        $quantity = $items->filter(fn ($i) => $target->target_type === 'product' && $i['product']->id == $target->target_id)->sum('quantity');

        if ($quantity < $rule->min_quantity) {
            return null;
        }

        $rule->description = "هدي عند شراء {$rule->min_quantity} من العروض المحددة → هدية: " .
            ucfirst($giftTarget->target_type) . " ID: {$giftTarget->target_id}";

        return $rule;
    }

    /**
     * A product that was sold and later deleted: it is hidden from the shop but stays in the
     * customer's order history (soft delete).
     */
    private function orderForADeletedProduct(): void
    {
        $product = $this->at(now()->subDays(50), function () {
            $product = Product::create([
                'name_ar' => 'أقراص استحلاب للحلق (متوقف)',
                'name_en' => 'Throat Lozenges (discontinued)',
                'description_ar' => 'لم يعد متوفرًا.',
                'description_en' => 'No longer sold.',
                'image' => 'products/throat-lozenges.png',
            ]);
            $size = $product->sizes()->create(['size' => '16 lozenges', 'price' => 30, 'stock' => 0]);
            ProductSize::setStock($size->id, 10, StockMovement::TYPE_INITIAL, ['admin_id' => Admin::value('id'), 'reason' => 'Product created']);

            return $product;
        });

        $this->placeOrder(User::where('email', 'omar@example.com')->firstOrFail(), [['Throat Lozenges (discontinued)', 0, 2]], 'completed', 40, 'cash', null, 'earned');

        $product->delete();
    }

    /**
     * Runs $callback with the clock set to $at, then puts the clock back as it was.
     */
    private function at(Carbon $at, callable $callback)
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($at);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
