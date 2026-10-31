<?php

namespace App\Modules\Cart\Repositories;

use App\Models\Cart;
use App\Support\Money;
use App\Modules\Shared\Repositories\BaseRepository;

class CartRepository extends BaseRepository
{
    public function __construct(private Cart $model)
    {
        parent::__construct($model);
    }

    public function getUserCart($userId)
    {
        return Cart::with('product')->where('user_id', $userId)->get();
    }

   public function addOrUpdate(array $data, int $availableStock)
{
    $cartItem = Cart::where('user_id', $data['user_id'])
                    ->where('product_id', $data['product_id'])
                    ->where('product_size_id', $data['product_size_id']) // ✅ مهم جداً
                    ->first();

    if ($cartItem) {
        // Never let the merged quantity exceed the stock, and use the current price.
        $cartItem->product_quantity = min($cartItem->product_quantity + $data['product_quantity'], $availableStock);
        $cartItem->price_at_time = $data['price_at_time'];
        $cartItem->original_price = $data['original_price'];
        $cartItem->total_price = Money::fromMinor(Money::toMinor($cartItem->price_at_time) * $cartItem->product_quantity);
        $cartItem->save();
    } else {
        $cartItem = Cart::create($data);
    }

    return $cartItem;
}

/**
 * Delete a cart item only if it belongs to the given user.
 */
public function deleteUserItem($userId, $id)
{
    return Cart::where('user_id', $userId)->where('id', $id)->delete() > 0;
}


    // public function updateQuantity($cartId, $quantity)
    //     {
    //         $cart = Cart::findOrFail($cartId);
    //         $cart->product_quantity = $quantity;
    //         $cart->total_price = $quantity * $cart->price_at_time;
    //         $cart->save();
    //         return $cart;
    //     }
    // public function clearCart($userId)
    // {
    //     return Cart::where('user_id', $userId)->delete();
    // }
}


