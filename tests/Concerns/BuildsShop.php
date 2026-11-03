<?php

namespace Tests\Concerns;

use App\Models\Address;
use App\Models\Cart;
use App\Models\City;
use App\Models\Country;
use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\User;

trait BuildsShop
{
    protected function makeSize(float $price = 100, int $stock = 10): ProductSize
    {
        $product = Product::create([
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'image' => 'products/test.png',
        ]);

        return $product->sizes()->create(['size' => 'M', 'price' => $price, 'stock' => $stock]);
    }

    protected function putInCart(User $user, ProductSize $size, int $quantity, ?float $storedPrice = null): Cart
    {
        $price = $storedPrice ?? $size->price;

        return Cart::create([
            'user_id' => $user->id,
            'product_id' => $size->product_id,
            'product_size_id' => $size->id,
            'product_quantity' => $quantity,
            'price_at_time' => $price,
            'original_price' => $size->price,
            'total_price' => $price * $quantity,
        ]);
    }

    protected function makeAddress(User $user, string $city = 'Cairo'): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'governorate' => $city,
            'city' => $city,
            'street' => 'Main street',
            'type' => 'home',
        ]);
    }

    /**
     * A delivery zone covering the city named $governorate, with one rate. (Delivery prices
     * used to be rows of delivery_prices; checkout now prices through zones and rates.)
     */
    protected function makeDeliveryPrice(string $governorate = 'Cairo', float $price = 5, array $rate = []): DeliveryRate
    {
        $country = Country::default();
        $city = City::named($governorate)->first() ?? City::create(['name' => $governorate, 'country_id' => $country->id]);
        $zone = DeliveryZone::create(['country_id' => $country->id, 'name' => $governorate]);
        $zone->locations()->create(['city_id' => $city->id]);

        return $zone->rates()->create(array_merge(['fee' => $price], $rate));
    }

    protected function makeOrder(User $user, ProductSize $size, int $quantity = 1, string $status = 'pending'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'payment_method' => 'cash',
            'delivery_fee' => 0,
            'total_price' => $size->price * $quantity,
            'points_earned' => 0,
            'status' => $status,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $size->product_id,
            'product_size_id' => $size->id,
            'quantity' => $quantity,
            'price_at_time' => $size->price,
            'original_price' => $size->price,
            'total_price' => $size->price * $quantity,
        ]);

        return $order;
    }

    /** The cash of an order built with makeOrder() was collected (checkout creates the payment itself). */
    protected function payInCash(Order $order): \App\Models\Payment
    {
        $payment = \App\Models\Payment::create([
            'order_id' => $order->id, 'provider' => \App\Models\Payment::PROVIDER_COD, 'internal_reference' => 'PAY-T'.$order->id,
            'amount' => $order->total_price, 'status' => 'paid', 'collection_status' => 'collected',
            'collected_amount' => $order->total_price, 'paid_at' => now(),
        ]);
        app(\App\Modules\Payments\Services\PaymentService::class)->projectOrder($order->id);

        return $payment;
    }

    /**
     * An order paid by ZainCash before ZainCash was retired, shaped like the migrated history:
     * the order keeps payment_method "zaincash" and its receipt; its payment is a legacy
     * manual transfer. ZainCash itself can no longer be chosen.
     */
    protected function makeHistoricalZainCashOrder(User $user, string $paymentStatus = 'awaiting_verification', string $status = 'pending'): Order
    {
        $method = \App\Models\PaymentMethod::where('code', 'zaincash')->firstOrFail();
        $order = $this->makeOrder($user, $this->makeSize(price: 40), 1, $status);
        $order->forceFill([
            'payment_method' => 'zaincash',
            'payment_method_id' => $method->id,
            'payment_image' => 'payments/zaincash-receipt.jpg',
            'payment_status' => $paymentStatus,
        ])->save();

        \App\Models\Payment::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'provider' => \App\Models\Payment::PROVIDER_MANUAL_TRANSFER,
            'internal_reference' => sprintf('PAY-L%08d', $order->id),
            'amount' => $order->total_price,
            'status' => $paymentStatus,
            'receipt_path' => 'payments/zaincash-receipt.jpg',
        ]);

        return $order->fresh();
    }

    /**
     * The order earns $points as checkout records them: orders.points_earned and a ledger lot.
     * $usable activates the lot now (as completion or payment would); $earnedAt backdates it
     * (its expiry follows from the validity setting).
     */
    protected function earnPoints(Order $order, int $points, bool $usable = true, ?\Illuminate\Support\Carbon $earnedAt = null): \App\Models\LoyaltyLedgerEntry
    {
        $order->forceFill(['points_earned' => $points])->save();
        $loyalty = app(\App\Modules\Points\Services\LoyaltyService::class);
        $lot = $loyalty->recordEarn($order->fresh());

        if ($usable) {
            $lot = $loyalty->ledger()->activate($lot, $loyalty->settings()->validityDays());
        }
        if ($earnedAt) {
            $days = $loyalty->settings()->validityDays();
            $lot->forceFill(['earned_at' => $earnedAt, 'expires_at' => $days === null ? null : $earnedAt->copy()->addDays($days)])->save();
        }

        return $lot->fresh();
    }
}
