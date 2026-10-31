<?php

namespace App\Modules\Cart;

use App\Models\Cart;
use App\Models\Coupon;

use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Money;
use App\Support\StoreClock;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Modules\Cart\Services\CartService;

class CartController extends Controller
{
    public function __construct(private CartService $cartService) {}

    // public function showCart(Request $request)
    // {
    //     $user = $request->user();
    //     if (!$user) {
    //         Log::error('Unauthenticated user attempt');
    //         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    //     }

    //     $cartItems = $this->cartService->getCart($user->id);

    //     $data = [];
    //     $totalPrice = 0;

    //     foreach ($cartItems as $item) {
    //         $product = $item->product;

    //         if (!$product) {
    //             continue;
    //         }

    //         $originalPrice = $product->price;
    //         $discountedPrice = $originalPrice;
    //         $discountPercentage = 0;



    //         $quantity = $item->product_quantity;
    //         $lineTotal = $discountedPrice * $quantity;
    //         $totalPrice += $lineTotal;

    //         $data[] = [
    //             'product_id' => $product->id,
    //             'name' => $product->name,
    //             'original_price' => $originalPrice,
    //             'discounted_price' => round($discountedPrice, 2),
    //             'discount_percentage' => $discountPercentage,
    //             'quantity' => $quantity,
    //             'main_image' => $product->image ? asset($product->image) : null,
    //             'line_total' => round($lineTotal, 2), // لو حابة ترجعي الس لكل منتج * الكمية
    //         ];
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'data' => $data,
    //         'total_price' => round($totalPrice, 2),
    //     ]);
    // }

public function showCart(Request $request)
{
    $user = $request->user();
    if (!$user) {
        \Log::error('Unauthenticated user attempt');
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $cartItems = $this->cartService->getCart($user->id);

    $data = [];
    $totalMinor = 0;
    $adjustments = [];

    foreach ($cartItems as $item) {
        $product = $item->product;
        $size = $item->size;

        // لو المنتج أو الحجم غير موجود → نحذفه من السلة
        if (!$product || !$size || $size->stock <= 0) {
            $adjustments[] = [
                'product_id' => $item->product_id,
                'name' => $product?->name,
                'size' => $size?->size,
                'reason' => 'out_of_stock',
                'removed_quantity' => $item->product_quantity,
            ];
            $item->delete();
            continue;
        }

        // لو الكمية في السلة > المخزون → نحدثها
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

        $originalPrice = $size->price;

        $currentPrice = $product->priceForSize($size);

        $lineTotalMinor = Money::toMinor($currentPrice) * $item->product_quantity;

        $item->price_at_time = $currentPrice;
        $item->original_price = $originalPrice;
        $item->total_price = Money::fromMinor($lineTotalMinor);
        $item->save();

        $lineTotal = Money::fromMinor($lineTotalMinor);
        $totalMinor += $lineTotalMinor;

        $discountPercentage = null;
        if ($originalPrice > 0) {
            $discountPercentage = round((($originalPrice - $currentPrice) / $originalPrice) * 100);
        }

        $data[] = [
            'id' => $item->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'original_price' => $originalPrice,
            'discounted_price' => round($currentPrice, 2),
            'discount_percentage' => $discountPercentage,
            'quantity' => $item->product_quantity,
            'main_image' => $product->image_url,
            'line_total' => round($lineTotal, 2),
            'size' => $size->size,
            'stock_available' => $size->stock,
        ];
    }

    $response = [
        'success' => true,
        'data' => $data,
        'total_price' => Money::fromMinor($totalMinor),
    ];

    // Tell the app why lines disappeared or shrank (new key; existing keys unchanged).
    if ($adjustments) {
        $response['adjustments'] = $adjustments;
    }

    return response()->json($response);
}


// public function addToCart(Request $request)
// {
//     $request->validate([
//         'product_id'   => 'required|exists:products,id',
//         'product_size' => 'required|exists:product_sizes,id',
//         'quantity'     => 'required|integer|min:1',
//     ]);

//     $product = Product::find($request->product_id);
//     $productSize = ProductSize::find($request->product_size);

//     $user = $request->user();
//     if (!$user) {
//         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
//     }

//     $originalPrice = $productSize->price;
//     $discountedPrice = $originalPrice;

//     // صم rules
//     $ruleDiscount = $product->getActiveDiscount();
//     if ($ruleDiscount) {
//         if ($ruleDiscount->discount_type === 'fixed') {
//             $discountedPrice = max(0, $originalPrice - $ruleDiscount->discount_value);
//         } elseif ($ruleDiscount->discount_type === 'percent') {
//             $discountedPrice = round($originalPrice * (1 - $ruleDiscount->discount_value / 100), 2);
//         }
//     }

//     // صم flash sale
//     if (
//         $product->saleable_type === FlashSale::class &&
//         $product->saleable &&
//         $product->saleable->is_active
//     ) {
//         $start = Carbon::parse($product->saleable->date . ' ' . $product->saleable->time);
//         $end = $start->copy()->addHour();

//         if (now()->between($start, $end)) {
//             $flashDiscount = max(0, $originalPrice - $product->saleable->discount_value);
//             $discountedPrice = min($discountedPrice, $flashDiscount);
//         }
//     }

//     $totalPrice = $discountedPrice * $request->quantity;

//     $data = $this->cartService->addToCart([
//         'user_id'         => $user->id,
//         'product_id'      => $product->id,
//         'product_size_id' => $productSize->id, //  استخدمي الاس الحيح
//         'product_quantity'=> $request->quantity,
//         'price_at_time'   => $discountedPrice,
//         'total_price'     => $totalPrice,
//         'original_price'  => $originalPrice,
//     ]);

//     return response()->json([
//         'success' => true,
//         'message' => 'تمت اإضافة إلى السلة بالسع بعد الخصم',
//         'data' => $data
//     ]);
// }

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
            'message' => 'هذا المقاس غير متوفر حالياً في المخزون',
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

    return response()->json([
        'success' => true,
        'message' => 'تمت الإضافة إلى السلة',
        'data' => $data,
    ]);
}


   public function removeItem(Request $request, $id)
{
    $removed = $this->cartService->removeFromCart($request->user()->id, $id);

    if (!$removed) {
        return response()->json([
            'success' => false,
            'message' => 'لم يتم لعثور على العنصر أو فشل الحذف'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'م الحذف من السلة'
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

    $cartItem = Cart::where('user_id', $user->id)
        ->where('product_id', $request->product_id)
        ->when($sizeId, fn($query) => $query->where('product_size_id', $sizeId))
        ->first();

    if (!$cartItem) {
        return response()->json(['success' => false, 'message' => 'العنصر غير موجود في السلة'], 404);
    }

    $product = $cartItem->product;

    // The product is hidden once every size is sold out (or it was deleted).
    if (!$product) {
        return response()->json([
            'success' => false,
            'message' => 'المنتج غير متوفر حالياً في المخزون',
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
                    'message' => 'الكمية المطلوبة غير متوفرة في المخزون',
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
                'message' => 'الحجم الجديد غير متوفر في المخزون',
            ], 400);
        }

        $finalPrice = $product->priceForSize($newSize);

        // لو فيه عنصر بنفس الحجم الجديد → دمج
        $existingItem = Cart::where('user_id', $user->id)
            ->where('product_id', $request->product_id)
            ->where('product_size_id', $newSize->id)
            ->first();

        if ($existingItem) {
            $newTotalQty = $existingItem->product_quantity + $cartItem->product_quantity;

            // لو الكمية أكبر من المخزون → نستخدم الحد الأقصى
            if ($newTotalQty > $newSize->stock) {
                $newTotalQty = $newSize->stock;
            }

            $existingItem->product_quantity = $newTotalQty;
            $existingItem->price_at_time = $finalPrice;
            $existingItem->original_price = $newSize->price;
            $existingItem->total_price = Money::fromMinor(Money::toMinor($finalPrice) * $existingItem->product_quantity);
            $existingItem->save();

            $cartItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الحجم ودمج الكمية بالسعر الجديد',
                'data' => $existingItem,
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
        // تحديث السعر فقط لو الكمية بس اتغيرت
        $size = $cartItem->size;
        $stock = $size->stock ?? 0;

        // A sold-out line cannot be edited (it used to be saved with quantity 0).
        if ($stock <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'المنتج غير متوفر حالياً في المخزون',
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

    $cartItem->save();

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث العنصر بنجاح مع التحقق من المخزون والسعر',
        'data' => $cartItem,
    ]);
}


    public function applyCoupon(Request $request)
    {
        // Validate the request first
        $request->validate([
            'coupon' => 'required|string'
        ]);

        // Find active coupon
        $coupon = Coupon::where('code', $request->coupon)
                       ->where('is_active', true)
                       ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'كود الخصم غير صالح'
            ], 400);
        }

        // Check if user is authenticated
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تسج الدخول أولاً'
            ], 401);
        }

        // Check coupon validity dates (same store clock as checkout).
        $now = StoreClock::now();
        if ($coupon->start_at && $now->lt($coupon->start_at)) {
            return response()->json([
                'success' => false,
                'message' => 'كود الخصم غير فعا بعد'
            ], 400);
        }

        if ($coupon->end_at && $now->gt($coupon->end_at)) {
            return response()->json([
                'success' => false,
                'message' => 'كود الخصم انتهت صلايته'
            ], 400);
        }

        // Check usage limits
        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return response()->json([
                'success' => false,
                'message' => 'تم استنا عدد استخدامات كود الخصم'
            ], 400);
        }

        // Check if user already used this coupon (if once_per_user is true)
        if ($coupon->once_per_user && $coupon->users()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لقد استخدمت ها الكود من قبل'
            ], 400);
        }

        // Get cart items and calculate total
        $cartItems = $this->cartService->getCart($user->id);
        // Preview on the products subtotal; checkout applies the same percentage to subtotal + delivery.
        // Prices come from the catalogue now, as at checkout, not from the stored cart rows.
        $totalMinor = $cartItems->sum(fn ($item) => $item->product && $item->size
            ? Money::toMinor($item->product->priceForSize($item->size)) * $item->product_quantity
            : 0);
        $discountMinor = min(Money::percentOf($totalMinor, $coupon->discount_value), $totalMinor);

        $total = Money::fromMinor($totalMinor);
        $discount = Money::fromMinor($discountMinor);
        $discountedTotal = Money::fromMinor($totalMinor - $discountMinor);

        // This endpoint only previews the discount. Usage is recorded once, at checkout;
        // recording it here consumed the coupon without an order and made
        // "once per user" coupons fail silently at checkout.

        return response()->json([
            'success' => true,
            'message' => 'تم طبيق كود الخصم',
            'discount' => round($discount, 2),
            'total_after_discount' => round($discountedTotal, 2),
            'original_total' => round($total, 2)
        ]);
    }
}
