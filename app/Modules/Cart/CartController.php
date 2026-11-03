<?php

namespace App\Modules\Cart;

use App\Models\Cart;
use App\Models\Address;
use App\Models\Coupon;
use App\Models\User;
use App\Modules\Coupon\Services\CouponEvaluator;
use App\Modules\Promotions\Engine\PricingResult;
use App\Modules\Promotions\Services\CartPricingService;
use App\Modules\Promotions\Services\CartPromotionSync;

use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Cart\Services\CartService;
use Illuminate\Support\Facades\DB;

/**
 * The customer's cart. Every change re-prices the whole cart through the promotion engine and
 * re-syncs the promotional gift lines (CartPromotionSync), so a gift appears as soon as it is
 * earned and disappears as soon as it is not. Prices always come from the database.
 *
 * Responses keep their original keys; promotion data is added under new keys
 * (pricing, gift_changes, and is_gift / gift on lines).
 */
class CartController extends Controller
{
    public function __construct(
        private CartService $cartService,
        private CartPricingService $pricing,
        private CartPromotionSync $gifts,
    ) {}

    public function showCart(Request $request)
    {
        $request->validate([
            'coupon' => 'nullable|string|max:255',
            'address_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $adjustments = [];

        $customerLines = Cart::customerLines()->with(['product', 'size'])->where('user_id', $user->id)->orderBy('id')->get()
            ->filter(function (Cart $item) use (&$adjustments) {
                $product = $item->product;
                $size = $item->size;

                // Sold out, deleted, or a size of another product: the line goes.
                if (!$product || !$size || $size->product_id !== $item->product_id || $size->stock <= 0) {
                    $adjustments[] = [
                        'product_id' => $item->product_id,
                        'name' => $product?->name,
                        'size' => $size?->size,
                        'reason' => 'out_of_stock',
                        'removed_quantity' => $item->product_quantity,
                    ];
                    $item->delete();

                    return false;
                }

                // More in the cart than in stock: the quantity goes down to the stock.
                if ($item->product_quantity > $size->stock) {
                    $adjustments[] = [
                        'product_id' => $item->product_id,
                        'name' => $product->name,
                        'size' => $size->size,
                        'reason' => 'quantity_reduced',
                        'requested_quantity' => $item->product_quantity,
                        'available_quantity' => $size->stock,
                    ];
                    $item->product_quantity = $size->stock;
                }

                return true;
            })->values();

        [$result, $changes] = $this->reprice($user, $customerLines, $request->input('coupon'), $this->address($user, $request->integer('address_id')), $request->integer('address_id') === -1);

        $data = [];
        foreach ($customerLines as $item) {
            $line = $result->line($item->id);
            $originalPrice = $item->size->price;
            $currentPrice = Money::fromMinor($line->unit);

            $discountPercentage = null;
            if ($originalPrice > 0) {
                $discountPercentage = round((($originalPrice - $currentPrice) / $originalPrice) * 100);
            }

            $data[] = [
                'id' => $item->id,
                'product_id' => $item->product->id,
                'name' => $item->product->name,
                'original_price' => $originalPrice,
                'discounted_price' => round($currentPrice, 2),
                'discount_percentage' => $discountPercentage,
                'quantity' => $item->product_quantity,
                'main_image' => $item->product->image_url,
                'line_total' => Money::fromMinor($line->total()),
                'size' => $item->size->size,
                'stock_available' => $item->size->stock,
                // Added fields.
                'product_size_id' => $item->size->id,
                'is_gift' => false,
                'editable' => true,
                'promotions' => $line->toArray()['promotions'],
            ];
        }

        $giftLines = Cart::giftLines()->with(['product', 'size'])->where('user_id', $user->id)->orderBy('id')->get()->keyBy('gift_key');
        foreach ($result->gifts as $gift) {
            $row = $giftLines->get($gift['gift_key']);
            $data[] = $this->giftLine($row, $result->giftToArray($gift));
        }

        $response = [
            'success' => true,
            'data' => $data,
            'total_price' => Money::fromMinor($result->subtotal),
            'pricing' => $this->pricingSummary($result),
        ];

        // Tell the app why lines disappeared or shrank (new key; existing keys unchanged).
        if ($adjustments) {
            $response['adjustments'] = $adjustments;
        }
        if ($changes) {
            $response['gift_changes'] = $changes;
        }

        return response()->json($response);
    }

    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id'   => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            // The size must belong to the product, otherwise another product's (cheaper) size price would be used.
            'product_size' => ['required', 'integer', Rule::exists('product_sizes', 'id')->where('product_id', $request->input('product_id'))],
            'quantity'     => 'required|integer|min:1|max:1000',
        ]);

        $productSize = ProductSize::findOrFail($request->product_size);

        // Checked before loading the product: a fully sold-out product is hidden from the API,
        // and the customer should get this message rather than a generic 404.
        if ($productSize->stock <= 0) {
            return response()->json([
                'success' => false,
                'message' => api_trans('cart.size_out_of_stock'),
            ], 400);
        }

        $product = Product::findOrFail($request->product_id);

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // ✅ تأكد الكمية <= المخزون
        $quantity = min($request->quantity, $productSize->stock);

        $originalPrice = $productSize->price;
        $finalPrice = $product->priceForSize($productSize);
        $totalPrice = Money::fromMinor(Money::toMinor($finalPrice) * $quantity);

        $data = $this->cartService->addToCart([
            'user_id'          => $user->id,
            'product_id'       => $product->id,
            'product_size_id'  => $productSize->id,
            'product_quantity' => $quantity,
            'price_at_time'    => $finalPrice,
            'total_price'      => $totalPrice,
            'original_price'   => $originalPrice,
        ], $productSize->stock);

        [$result, $changes] = $this->repriceCart($user);

        return response()->json([
            'success' => true,
            'message' => api_trans('cart.added'),
            'data' => $data->fresh(),
            'pricing' => $this->pricingSummary($result),
            'gift_changes' => $changes,
        ]);
    }

    public function removeItem(Request $request, $id)
    {
        $user = $request->user();

        if (Cart::giftLines()->where('user_id', $user->id)->whereKey($id)->exists()) {
            return response()->json(['success' => false, 'message' => api_trans('promotions.gift.not_editable'), 'code' => 'gift_not_editable'], 422);
        }

        $removed = $this->cartService->removeFromCart($user->id, $id);

        if (!$removed) {
            return response()->json([
                'success' => false,
                'message' => api_trans('cart.remove_failed')
            ], 404);
        }

        [$result, $changes] = $this->repriceCart($user);

        return response()->json([
            'success' => true,
            'message' => api_trans('cart.removed'),
            'pricing' => $this->pricingSummary($result),
            'gift_changes' => $changes,
        ]);
    }

    public function updateQuantity(Request $request)
    {
        $sizeOfProduct = Rule::exists('product_sizes', 'id')->where('product_id', $request->input('product_id'));

        $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'operation' => 'nullable|in:increment,decrement',
            'old_size_id' => ['nullable', 'integer', $sizeOfProduct],
            'new_size_id' => ['nullable', 'integer', $sizeOfProduct],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $sizeId = $request->old_size_id ?? $request->new_size_id;

        $cartItem = Cart::customerLines()->where('user_id', $user->id)
            ->where('product_id', $request->product_id)
            ->when($sizeId, fn($query) => $query->where('product_size_id', $sizeId))
            ->first();

        if (!$cartItem) {
            // Only a gift line matches: gifts are controlled by the promotion.
            if (Cart::giftLines()->where('user_id', $user->id)->where('product_id', $request->product_id)->exists()) {
                return response()->json(['success' => false, 'message' => api_trans('promotions.gift.not_editable'), 'code' => 'gift_not_editable'], 422);
            }

            return response()->json(['success' => false, 'message' => api_trans('cart.item_not_found')], 404);
        }

        $product = $cartItem->product;

        // The product is hidden once every size is sold out (or it was deleted).
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => api_trans('cart.out_of_stock'),
            ], 400);
        }

        // 1. تعديل الكمية
        if ($request->filled('operation')) {
            $stock = $cartItem->size->stock ?? 0;

            if ($request->operation === 'increment') {
                if ($cartItem->product_quantity < $stock) {
                    $cartItem->product_quantity += 1;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => api_trans('cart.quantity_unavailable'),
                    ], 400);
                }
            } elseif ($request->operation === 'decrement') {
                $cartItem->product_quantity = max(1, $cartItem->product_quantity - 1);
            }
        }

        // 2. تعديل الحجم
        if ($request->filled('old_size_id') && $request->filled('new_size_id')) {
            $newSize = ProductSize::find($request->new_size_id);

            if (!$newSize || $newSize->stock <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => api_trans('cart.new_size_unavailable'),
                ], 400);
            }

            $finalPrice = $product->priceForSize($newSize);

            // لو فيه عنصر بنفس الحجم الجديد → دمج
            $existingItem = Cart::customerLines()->where('user_id', $user->id)
                ->where('product_id', $request->product_id)
                ->where('product_size_id', $newSize->id)
                ->first();

            if ($existingItem) {
                $newTotalQty = min($existingItem->product_quantity + $cartItem->product_quantity, $newSize->stock);

                $existingItem->product_quantity = $newTotalQty;
                $existingItem->price_at_time = $finalPrice;
                $existingItem->original_price = $newSize->price;
                $existingItem->total_price = Money::fromMinor(Money::toMinor($finalPrice) * $existingItem->product_quantity);
                $existingItem->save();

                $cartItem->delete();

                [$result, $changes] = $this->repriceCart($user);

                return response()->json([
                    'success' => true,
                    'message' => api_trans('cart.size_merged'),
                    'data' => $existingItem->fresh(),
                    'pricing' => $this->pricingSummary($result),
                    'gift_changes' => $changes,
                ]);
            }

            // تغيير الحجم في العنصر الحالي
            $cartItem->product_size_id = $newSize->id;
            $cartItem->price_at_time = $finalPrice;
            $cartItem->original_price = $newSize->price;

            // تحقق من المخزون بعد تغيير الحجم
            if ($cartItem->product_quantity > $newSize->stock) {
                $cartItem->product_quantity = $newSize->stock;
            }

            $cartItem->total_price = Money::fromMinor(Money::toMinor($finalPrice) * $cartItem->product_quantity);
        } else {
            $size = $cartItem->size;
            $stock = $size->stock ?? 0;

            // A sold-out line cannot be edited (it used to be saved with quantity 0).
            if ($stock <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => api_trans('cart.out_of_stock'),
                ], 400);
            }

            if ($cartItem->product_quantity > $stock) {
                $cartItem->product_quantity = $stock;
            }

            $finalPrice = $product->priceForSize($size);

            $cartItem->price_at_time = $finalPrice;
            $cartItem->original_price = $size->price;
            $cartItem->total_price = Money::fromMinor(Money::toMinor($finalPrice) * $cartItem->product_quantity);
        }

        try {
            $cartItem->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Another request just added the new size as its own line: one line per size; reload and retry.
            return response()->json(['success' => false, 'message' => api_trans('cart.changed'), 'code' => 'cart_changed'], 409);
        }

        [$result, $changes] = $this->repriceCart($user);

        return response()->json([
            'success' => true,
            'message' => api_trans('cart.updated'),
            'data' => $cartItem->fresh(),
            'pricing' => $this->pricingSummary($result),
            'gift_changes' => $changes,
        ]);
    }

    /**
     * Coupon preview: the whole cart priced by the engine with this coupon, exactly as checkout
     * will price it. Send address_id (-1 = pickup) to include the delivery fee; without it the
     * preview covers the items only, as it always did.
     */
    public function applyCoupon(Request $request, CouponEvaluator $coupons)
    {
        $request->validate([
            'coupon' => 'required|string|max:255',
            'address_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $addressId = $request->integer('address_id');
        $customerLines = Cart::customerLines()->with(['product', 'size'])->where('user_id', $user->id)->orderBy('id')->get();

        [$result, $changes] = $this->reprice($user, $customerLines, $request->coupon, $this->address($user, $addressId), $addressId === -1);

        if (!$result->coupon) {
            $rejection = $result->couponRejection ?? ['reason' => CouponEvaluator::INVALID];
            $reason = $rejection['reason'];
            $coupon = Coupon::where('code', trim($request->coupon))->first();
            $message = trans()->has("cart.coupon.{$reason}", api_locale())
                ? api_trans("cart.coupon.{$reason}", ['amount' => (float) ($coupon?->min_order_amount ?? 0)])
                : ($rejection['message'] ?? api_trans('cart.coupon.invalid'));

            return response()->json(['success' => false, 'message' => $message, 'code' => $reason, 'pricing' => $this->pricingSummary($result)], 400);
        }

        return response()->json([
            'success' => true,
            'message' => api_trans('cart.coupon.applied'),
            'discount' => round(Money::fromMinor($result->couponDiscount), 2),
            'total_after_discount' => round(Money::fromMinor($result->total()), 2),
            'original_total' => round(Money::fromMinor($result->subtotal), 2),
            // Added fields.
            'delivery_fee' => Money::fromMinor($result->deliveryFee),
            'delivery_included' => $addressId !== 0,
            'coupon_type' => $result->coupon->type ?? Coupon::TYPE_PERCENT,
            'applies_to_delivery' => $coupons->appliesToDelivery($result->coupon),
            'pricing' => $this->pricingSummary($result),
            'gift_changes' => $changes,
        ]);
    }

    /** Re-price the stored cart (no coupon, no delivery) and sync the gifts. */
    private function repriceCart(User $user): array
    {
        $customerLines = Cart::customerLines()->with(['product', 'size'])->where('user_id', $user->id)->orderBy('id')->get();

        return $this->reprice($user, $customerLines, null, null, false);
    }

    /**
     * Price the cart, store the authoritative prices on the customer lines and make the gift
     * lines match the result — in one transaction, with the customer's row locked so two
     * concurrent requests from the same customer cannot interleave their gift updates.
     *
     * @return array{0: PricingResult, 1: list<array>}
     */
    private function reprice(User $user, $customerLines, ?string $coupon, ?Address $address, bool $pickup): array
    {
        return DB::transaction(function () use ($user, $customerLines, $coupon, $address, $pickup) {
            User::whereKey($user->id)->lockForUpdate()->first();

            [$result] = $this->pricing->price($user, $customerLines, $coupon, $address, $pickup);

            foreach ($customerLines as $item) {
                if ($line = $result->line($item->id)) {
                    $item->price_at_time = Money::fromMinor($line->unit);
                    $item->original_price = Money::fromMinor($line->originalUnit);
                    $item->total_price = Money::fromMinor($line->total());
                    $item->save();
                }
            }

            return [$result, $this->gifts->sync($user, $result)];
        });
    }

    private function address(User $user, int $addressId): ?Address
    {
        return $addressId > 0 ? Address::where('user_id', $user->id)->find($addressId) : null;
    }

    /** A gift line in the cart listing: same keys as a normal line, price 0, not editable. */
    private function giftLine(?Cart $row, array $gift): array
    {
        $product = $row?->product;

        return [
            'id' => $row?->id,
            'product_id' => $gift['product_id'],
            'name' => $gift['name'],
            'original_price' => $gift['original_price'],
            'discounted_price' => 0,
            'discount_percentage' => 100,
            'quantity' => $gift['quantity'],
            'main_image' => $product?->image_url,
            'line_total' => 0,
            'size' => $gift['size'],
            'stock_available' => $row?->size?->stock,
            'product_size_id' => $gift['product_size_id'],
            'is_gift' => true,
            'editable' => false,
            'gift' => [
                'label' => api_trans('promotions.gift.label'),
                'promotion_id' => $gift['promotion_id'],
                'promotion_name' => $gift['promotion_name'],
                'promotion_type' => $gift['promotion_type'],
                'reward_id' => $gift['reward_id'],
                'reward_type' => $gift['reward_type'],
                'requested_quantity' => $gift['requested_quantity'],
                'status' => $gift['status'],
            ],
            'promotions' => [],
        ];
    }

    private function pricingSummary(PricingResult $result): array
    {
        $summary = $result->toArray();
        unset($summary['lines']);

        return $summary;
    }
}
