<?php

namespace App\Modules\Cart\Repositories;

use App\Models\Cart;
use App\Models\User;
use App\Support\Money;
use App\Modules\Shared\Repositories\BaseRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CartRepository extends BaseRepository
{
    public function __construct(private Cart $model)
    {
        parent::__construct($model);
    }

    /** Every line: customer lines first (oldest first), then promotional gifts. */
    public function getUserCart($userId)
    {
        return Cart::with(['product', 'size'])->where('user_id', $userId)->orderBy('is_gift')->orderBy('id')->get();
    }

    /**
     * Merges into the customer's own line for this size; gift lines are never merged into. The
     * customer row is locked first (the lock checkout takes first too), so simultaneous adds of
     * the same item (a double tap) merge their quantities instead of creating a second line or
     * losing one of the increments; the unique key carts_one_customer_line_per_size backs it up.
     */
    public function addOrUpdate(array $data, int $availableStock)
    {
        return DB::transaction(function () use ($data, $availableStock) {
            User::whereKey($data['user_id'])->lockForUpdate()->first();

            $cartItem = Cart::customerLines()
                ->where('user_id', $data['user_id'])
                ->where('product_id', $data['product_id'])
                ->where('product_size_id', $data['product_size_id'])
                ->first();

            if (!$cartItem) {
                try {
                    return Cart::create($data + ['is_gift' => false]);
                } catch (UniqueConstraintViolationException) {
                    // Written meanwhile by a path that does not take the customer lock: merge into it.
                    $cartItem = Cart::customerLines()->where('user_id', $data['user_id'])->where('product_size_id', $data['product_size_id'])->firstOrFail();
                }
            }

            // Never let the merged quantity exceed the stock, and use the current price.
            $cartItem->product_quantity = min($cartItem->product_quantity + $data['product_quantity'], $availableStock);
            $cartItem->price_at_time = $data['price_at_time'];
            $cartItem->original_price = $data['original_price'];
            $cartItem->total_price = Money::fromMinor(Money::toMinor($cartItem->price_at_time) * $cartItem->product_quantity);
            $cartItem->save();

            return $cartItem;
        }, 3);
    }

    /**
     * Delete a customer line only if it belongs to the given user. Gift lines are managed by
     * the promotion engine and cannot be deleted here.
     */
    public function deleteUserItem($userId, $id)
    {
        return Cart::customerLines()->where('user_id', $userId)->where('id', $id)->delete() > 0;
    }
}
