<?php

namespace App\Modules\Cart\Services;

use App\Models\Cart;

use App\Modules\Cart\Repositories\CartRepository;



class CartService
{
    public function __construct(private CartRepository $cartRepository) {}


    
    public function addToCart(array $data)
    {
        return $this->cartRepository->addOrUpdate($data);
    }
 public function removeFromCart($cartId)
    {

        return $this->cartRepository->delete($cartId);
    }

    public function getCart($userId)
    {
        return $this->cartRepository->getUserCart($userId);
    }

    // public function updateQuantity($cartId, $quantity)
    // {
    //     return $this->cartRepository->updateQuantity($cartId, $quantity);
    // }


    // public function clearCart($userId)
    // {
    //     return $this->cartRepository->clearCart($userId);
    // }
}

