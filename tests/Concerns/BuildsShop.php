<?php

namespace Tests\Concerns;

use App\Models\Address;
use App\Models\Cart;
use App\Models\DeliveryPrice;
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

    protected function makeAddress(User $user, string $city = 'Baghdad'): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'governorate' => $city,
            'city' => $city,
            'street' => 'Main street',
            'type' => 'home',
        ]);
    }

    protected function makeDeliveryPrice(string $governorate = 'Baghdad', float $price = 5): DeliveryPrice
    {
        return DeliveryPrice::create(['governorate' => $governorate, 'price' => $price]);
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
}
