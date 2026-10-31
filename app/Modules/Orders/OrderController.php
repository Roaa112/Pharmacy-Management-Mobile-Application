<?php
namespace App\Modules\Orders;

use Carbon\Carbon;

use App\Models\ProductSize;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\{Order, OrderItem, Cart, Address, DeliveryPrice, Setting, DiscountRule};


class OrderController extends Controller
{

  public function points(Request $request)
  {
      $user = auth()->user();

    $orders = Order::where('user_id', $user->id)
                   ->select(
                       'id as order_name',  // ممكن تغا لـ name لو العمد موجود
                       'points_earned',
                       'points_status',
                       'created_at'
                   )
                   ->get();

    return response()->json([
        'orders' => $orders
    ]);
  }

// public function checkout(Request $request)
// {
//     $request->validate([
//       'address_id' => 'required|integer', // سمحن بـ -1
//         'payment_method' => 'required|in:cash,zaincash',
//         'payment_image' => 'nullable|image|required_if:payment_method,zaincash',
//               'notes' => 'nullable|string',
//        'coupon_code' => 'nullable|string'
//     ]);
//     $user = auth()->user();
//      $deliveryPriceAmount = 0;
//     $address = null;

//     if ($request->address_id != -1) {
//         $address = Address::findOrFail($request->address_id);

//         $deliveryPrice = DeliveryPrice::where('governorate', 'like', '%' . $address->city . '%')->first();

//         if (!$deliveryPrice) {
//             return response()->json(['message' => 'المينة غر عرة في دول مصارف اتوصل'], 422);
//         }

//         $deliveryPriceAmount = $deliveryPrice->price;
//     }
//     $cartItems = Cart::with('product')->where('user_id', $user->id)->get();
//     if ($cartItems->isEmpty()) {
//         return response()->json(['message' => 'عرة لتوق فارغة'], 422);
//     }

//     $subtotal = $cartItems->sum('total_price');
//     $total = $subtotal + $deliveryPriceAmount;

//     $giftDescription = null;
//     $giftRuleId = null;
//    $now = Carbon::now()->addHours(3);




//     $giftRules = DiscountRule::with('targets')
//         ->whereIn('discount_type', ['buy_x_get_y', 'amount_gift'])
//         ->where('starts_at', '<=', $now)
//         ->where('ends_at', '>=', $now)
//         ->get();

//     foreach ($giftRules as $rule) {
//         $buyTargets = $rule->targets->where('is_gift', false);
//         $giftTarget = $rule->targets->where('is_gift', true)->first();

//         if ($rule->discount_type === 'buy_x_get_y') {
//             $totalQty = 0;

//             foreach ($buyTargets as $target) {
//                 $matchedItems = $cartItems->filter(function ($item) use ($target) {
//                     return match ($target->target_type) {
//                         'product' => $item->product_id == $target->target_id,
//                         'brand' => $item->product->brand_id == $target->target_id,
//                         'category' => $item->product->category_id == $target->target_id,
//                         default => false,
//                     };
//                 });

//                 $totalQty += $matchedItems->sum('product_quantity');
//             }

//             if ($totalQty >= $rule->min_quantity) {
//                 $giftDescription = "هدية عد راء {$rule->min_quantity} ن العرض لحددة  هدية: " .
//                     ucfirst($giftTarget->target_type) . " ID: {$giftTarget->target_id}";
//                 $giftRuleId = $rule->id;
//                 break;
//             }
//         }

//         if ($rule->discount_type === 'amount_gift') {
//             $totalValue = 0;

//             foreach ($buyTargets as $target) {
//                 $matchedItems = $cartItems->filter(function ($item) use ($target) {
//                     return match ($target->target_type) {
//                         'product' => $item->product_id == $target->target_id,
//                         'brand' => $item->product->brand_id == $target->target_id,
//                         'category' => $item->product->category_id == $target->target_id,
//                         default => false,
//                     };
//                 });

//                 $totalValue += $matchedItems->sum('total_price');
//             }

//             if ($totalValue >= $rule->min_amount) {
//                 $giftDescription = "هدية ند الشراء قية {$rule->min_amount} من اعرو المحددة → ه: " .
//                     ucfirst($giftTarget->target_type) . " ID: {$giftTarget->target_id}";
//                 $giftRuleId = $rule->id;
//                 break;
//             }
//         }
//     }

//     // نقاط الولء
//     $settings = Setting::first();
//     $spendX = (float) $settings->spend_x ?? 0;
//     $getY = (int) $settings->get_y ?? 0;
//     $pointsEarned = $spendX > 0 ? floor($subtotal / $spendX) * $getY : 0;
//     $discountAmount = 0;
//     $couponId = null;

//     if ($request->filled('coupon_code')) {
//         $coupon = \App\Models\Coupon::where('code', $request->coupon_code)
//             ->where('is_active', true)
//             ->first();

//         if ($coupon) {
//             $now = now();
//             if (
//                 (!$coupon->start_at || $now->gte($coupon->start_at)) &&
//                 (!$coupon->end_at || $now->lte($coupon->end_at)) &&
//                 (!$coupon->usage_limit || $coupon->used_count < $coupon->usage_limit) &&
//                 (!$coupon->once_per_user || !$coupon->users()->where('user_id', $user->id)->exists())
//             ) {
//                 // Apply the discount
//                 $discountAmount = min($coupon->discount_value, $total); // Avoid negative total
//                 $total -= $discountAmount;
//                 $couponId = $coupon->id;
//             }
//         }
//     }

//     // إنشاء الل
//     $order = Order::create([
//         'user_id' => $user->id,
//        'address_id' => $request->address_id == -1 ? null : $address->id,
//         'payment_method' => $request->payment_method,
//         'payment_image' => $request->file('payment_image')?->store('payments', 'public'),
//           'delivery_fee' => $deliveryPriceAmount,
//         'total_price' => $total,
//         'gift_description' => $giftDescription,
//         'gift_rule_id' => $giftRuleId,
//         'coupon_id' => $couponId,
//         'points_earned' => $pointsEarned,
//       'notes'=>$request->notes,
//     ]);

//     foreach ($cartItems as $item) {
//         OrderItem::create([
//             'order_id' => $order->id,
//             'product_id' => $item->product_id,
//             'product_size_id' => $item->product_size_id,
//             'quantity' => $item->product_quantity,
//             'price_at_time' => $item->price_at_time,
//             'original_price' => $item->original_price,
//             'total_price' => $item->total_price,
//         ]);

//         $productSize = ProductSize::find($item->product_size_id);
//         if ($productSize) {
//             $productSize->decrement('stock', $item->product_quantity);
//         }
//     }

//     Cart::where('user_id', $user->id)->delete();
//  if ($couponId) {
//         $coupon->increment('used_count');
//         $coupon->users()->attach($user->id);
//     }
//     return response()->json([
//         'message' => 'تم إاء اطلب بنجا',
//         'order_id' => $order->id,
//         'total_price' => $order->total_price,
//         'points_earned' => $pointsEarned,
//         'gift_description' => $giftDescription,
//     ], 201);
// }



public function checkout(Request $request)
{
    $request->validate([
        'address_id' => 'required|integer', // سحن بـ -1
        'payment_method' => 'required|in:cash,zaincash,qicard',
        'payment_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120|required_if:payment_method,zaincash,qicard',
        'notes' => 'nullable|string|max:225',
        'coupon_code' => 'nullable|string|max:255'
    ]);

    $user = auth()->user();
    $deliveryPriceAmount = 0;
    $address = null;

    if ($request->address_id != -1) {
        // The address must belong to the user placing the order.
        $address = Address::where('user_id', $user->id)->findOrFail($request->address_id);

        $city = addcslashes($address->city, '%_\\');
        $deliveryPrice = DeliveryPrice::where('governorate', 'like', '%' . $city . '%')->first();

        if (!$deliveryPrice) {
            return response()->json(['message' => 'المدينة غير معرفة ضمن مصاريف التوصيل'], 422);
        }

        $deliveryPriceAmount = $deliveryPrice->price;
    }

    $paymentImage = $request->file('payment_image')?->store('payments', 'public');

    // Stock check, stock decrement, coupon usage and cart clearing happen atomically.
    // Cart rows and product sizes are locked so concurrent or repeated checkouts
    // cannot oversell stock or turn one cart into two orders.
    $result = DB::transaction(function () use ($request, $user, $address, $deliveryPriceAmount, $paymentImage) {
        $cartItems = Cart::with('product')->where('user_id', $user->id)->lockForUpdate()->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'عربة التسوق فارغة'], 422);
        }

        $sizes = ProductSize::whereIn('id', $cartItems->pluck('product_size_id')->filter())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // ✅ التقق من المخزون
        $stockErrors = [];

        foreach ($cartItems as $item) {
            $size = $sizes->get($item->product_size_id);
            $available = ($size && $item->product && $size->product_id == $item->product_id) ? $size->stock : 0;

            if ($available <= 0) {
                $stockErrors[] = [
                    'product' => $item->product->name ?? 'منتج غير معروف',
                    'size' => $size->size ?? '-',
                    'message' => 'المنتج غير متوفر حالياً في المخزون'
                ];
            } elseif ($item->product_quantity > $available) {
                $stockErrors[] = [
                    'product' => $item->product->name ?? 'منتج غير معروف',
                    'size' => $size->size ?? '-',
                    'message' => "الكمية المطلوبة غير متوفرة، المتاح فقط: {$available}"
                ];
            }
        }

        if (!empty($stockErrors)) {
            return response()->json([
                'message' => 'بعض المنتجات غير متوفرة بالكمات المطلوبة',
                'errors' => $stockErrors,
            ], 422);
        }

        // Prices are recalculated from the catalogue now; stored cart prices may be stale.
        // Totals are computed in integer minor units (see App\Support\Money).
        $lineTotals = [];
        foreach ($cartItems as $item) {
            $size = $sizes->get($item->product_size_id);
            $item->original_price = $size->price;
            $item->price_at_time = $item->product->priceForSize($size);
            $lineTotals[$item->id] = Money::toMinor($item->price_at_time) * $item->product_quantity;
            $item->total_price = Money::fromMinor($lineTotals[$item->id]);
        }

        $subtotalMinor = array_sum($lineTotals);
        $totalMinor = $subtotalMinor + Money::toMinor($deliveryPriceAmount);

        $giftDescription = null;
        $giftRuleId = null;
        // Promotion and coupon dates are local wall-clock times (see StoreClock).
        $now = StoreClock::now();

        // ✅ لهدايا بناءً على القواعد
        $giftRules = DiscountRule::with('targets')
            ->whereIn('discount_type', ['buy_x_get_y', 'amount_gift'])
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->get();

        foreach ($giftRules as $rule) {
            $buyTargets = $rule->targets->where('is_gift', false);
            $giftTarget = $rule->targets->where('is_gift', true)->first();

            if (!$giftTarget) {
                continue;
            }

            if ($rule->discount_type === 'buy_x_get_y') {
                $totalQty = 0;

                foreach ($buyTargets as $target) {
                    $matchedItems = $cartItems->filter(function ($item) use ($target) {
                        return match ($target->target_type) {
                            'product' => $item->product_id == $target->target_id,
                            'brand' => $item->product->brand_id == $target->target_id,
                            'category' => $item->product->category_id == $target->target_id,
                            default => false,
                        };
                    });

                    $totalQty += $matchedItems->sum('product_quantity');
                }

                if ($totalQty >= $rule->min_quantity) {
                    $giftDescription = "هدي عند شراء {$rule->min_quantity} من العروض المحددة → هدية: " .
                        ucfirst($giftTarget->target_type) . " ID: {$giftTarget->target_id}";
                    $giftRuleId = $rule->id;
                    break;
                }
            }

            if ($rule->discount_type === 'amount_gift') {
                $totalValue = 0;

                foreach ($buyTargets as $target) {
                    $matchedItems = $cartItems->filter(function ($item) use ($target) {
                        return match ($target->target_type) {
                            'product' => $item->product_id == $target->target_id,
                            'brand' => $item->product->brand_id == $target->target_id,
                            'category' => $item->product->category_id == $target->target_id,
                            default => false,
                        };
                    });

                    $totalValue += $matchedItems->sum(fn ($item) => $lineTotals[$item->id]);
                }

                if ($totalValue >= Money::toMinor($rule->min_amount)) {
                    $giftDescription = "هدية عند الشراء بقيمة {$rule->min_amount} من العروض المحددة → هدية: " .
                        ucfirst($giftTarget->target_type) . " ID: {$giftTarget->target_id}";
                    $giftRuleId = $rule->id;
                    break;
                }
            }
        }

        // ✅ حساب نقاط اللاء
        // Points are earned on the products subtotal (before delivery and coupon), as before.
        $settings = Setting::first();
        $spendXMinor = Money::toMinor($settings?->spend_x);
        $getY = (int) ($settings?->get_y ?? 0);
        // (float) keeps the JSON number type the app already receives (floor() used to return a float).
        $pointsEarned = $spendXMinor > 0 ? (float) (intdiv($subtotalMinor, $spendXMinor) * $getY) : 0;

        $coupon = null;

        if ($request->filled('coupon_code')) {
            $coupon = \App\Models\Coupon::where('code', $request->coupon_code)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($coupon && $this->couponIsUsableBy($coupon, $user, $now)) {
                // The coupon percentage applies to subtotal + delivery; it can never exceed the total.
                $discountMinor = min(Money::percentOf($totalMinor, $coupon->discount_value), $totalMinor);

                // نخصم المبلغ
                $totalMinor -= $discountMinor;
            } else {
                $coupon = null;
            }
        }

        $total = Money::fromMinor($totalMinor);

        // ✅ إناء الطلب
        $order = Order::create([
            'user_id' => $user->id,
            'address_id' => $address?->id,
            'payment_method' => $request->payment_method,
            'payment_image' => $paymentImage,
            'delivery_fee' => $deliveryPriceAmount,
            'total_price' => $total,
            'gift_description' => $giftDescription,
            'gift_rule_id' => $giftRuleId,
            'coupon_id' => $coupon?->id,
            'points_earned' => $pointsEarned,
            'notes' => $request->notes,
        ]);

        foreach ($cartItems as $item) {
            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'product_size_id' => $item->product_size_id,
                'quantity' => $item->product_quantity,
                'price_at_time' => $item->price_at_time,
                'original_price' => $item->original_price,
                'total_price' => $item->total_price,
                // Snapshot so the order keeps what was bought even if the product is renamed or deleted.
                'product_name_ar' => $item->product->name_ar,
                'product_name_en' => $item->product->name_en,
                'size_label' => $sizes->get($item->product_size_id)->size,
            ]);

            ProductSize::moveStock($item->product_size_id, -$item->product_quantity, StockMovement::TYPE_SALE, [
                'reference' => $orderItem,
                'user_id' => $user->id,
            ]);
        }

        Cart::where('user_id', $user->id)->delete();

        if ($coupon) {
            $coupon->increment('used_count');
            $coupon->users()->attach($user->id);
        }

        return response()->json([
            'message' => 'تم إنشاء الطلب بنجاح',
            'order_id' => $order->id,
            'total_price' => $order->total_price,
            'points_earned' => $pointsEarned,
            'gift_description' => $giftDescription,
        ], 201);
    });

    if ($result->getStatusCode() !== 201 && $paymentImage) {
        Storage::disk('public')->delete($paymentImage);
    }

    return $result;
}

private function couponIsUsableBy(\App\Models\Coupon $coupon, $user, Carbon $now): bool
{
    return (!$coupon->start_at || $now->gte($coupon->start_at))
        && (!$coupon->end_at || $now->lte($coupon->end_at))
        && (!$coupon->usage_limit || $coupon->used_count < $coupon->usage_limit)
        && (!$coupon->once_per_user || !$coupon->users()->where('user_id', $user->id)->exists());
}



public function updateStatus(Request $request, Order $order)
{
    $request->validate([
        'status' => 'required|in:pending,confirmed,canceled,completed,on_delivery',
    ]);

    $updated = DB::transaction(function () use ($request, $order) {
        // Lock the order so a double-submitted cancel cannot restore stock twice.
        $order = Order::lockForUpdate()->findOrFail($order->id);

        if (!$order->canTransitionTo($request->status)) {
            return false;
        }

        // Canceling returns the reserved quantities to stock (exactly once: canceled is final).
        if ($request->status === 'canceled') {
            foreach ($order->items as $item) {
                ProductSize::moveStock($item->product_size_id, $item->quantity, StockMovement::TYPE_CANCELLATION, [
                    'reference' => $item,
                    'admin_id' => auth('admin')->id(),
                    'reason' => "Order #{$order->id} canceled",
                ]);
            }
        }

        $order->status = $request->status;
        $order->save();

        return true;
    });

    if (!$updated) {
        return redirect()->back()->with('error', "لا يمكن تغيير حالة الطلب من {$order->status} إلى {$request->status}");
    }

    return redirect()->back()->with('success', 'تم تحديث حة الطب بنجا');
}


public function index(Request $request)
{
    $status = $request->query('status');
    $search = trim((string) $request->query('search'));

    $orders = Order::with(['user:id,name,phone', 'returnRequests:id,order_id,status'])
        ->withCount('items')
        ->when(in_array($status, array_keys($this->statusLabels()), true), fn ($q) => $q->where('status', $status))
        ->when($search !== '', function ($q) use ($search) {
            $q->where(function ($q) use ($search) {
                if (ctype_digit(ltrim($search, '#'))) {
                    $q->where('id', (int) ltrim($search, '#'));
                }
                $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        })
        ->latest()
        ->paginate(15)
        ->withQueryString();

   $totalEarned = Order::where('status', 'completed')
    ->whereDoesntHave('returnRequests', function ($query) {
        $query->whereIn('status', ['accepted']);
    })
    ->sum('total_price');

    $statusCounts = Order::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

    return view('dashboard.orders.index', [
        'orders' => $orders,
        'totalEarned' => $totalEarned,
        'statusCounts' => $statusCounts,
        'statusLabels' => $this->statusLabels(),
        'status' => $status,
        'search' => $search,
    ]);
}

public function show(Order $order)
{
    $order->load([
        'user:id,name,email,phone',
        'address',
        'items.product' => fn ($q) => $q->basic(),
        'items.size',
        'giftRule',
        'coupon:id,code,discount_value',
        'returnRequests',
    ]);

    // Only the transitions the server will accept are offered (see Order::canTransitionTo()).
    $nextStatuses = collect($this->statusLabels())
        ->filter(fn ($label, $value) => $value !== $order->status && $order->canTransitionTo($value));

    return view('dashboard.orders.show', [
        'order' => $order,
        'statusLabels' => $this->statusLabels(),
        'nextStatuses' => $nextStatuses,
    ]);
}

private function statusLabels(): array
{
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'on_delivery' => 'On delivery',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ];
}




 public function track($id)
    {
        $order = Order::where('user_id', auth()->id())->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'اط غي موجد'
            ], 404);
        }

        $statusMeaning = $this->getStatusMeaning($order->status);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'status' => $order->status,
            'status_meaning' => $statusMeaning,
            'last_update' => $order->updated_at->format('Y-m-d H:i'),
        ]);
    }

    private function getStatusMeaning($status)
    {
        return match ($status) {
            'pending' => 'قيد المرجعة ',
            'confirmed' => 'تم تكيد الطلب وجاري التحضير',
            'on_delivery' => 'جاري الشحن',
            'canceled' => 'تم إلغاء اطلب',
            'completed' => 'تم لاتوصيل بنجاح',
            default => 'غير معروف'
        };
    }




  public function details($id)
{
    $order = \App\Models\Order::with(['user', 'address', 'items.product.productImages', 'items.size'])
        ->where('user_id', auth()->id())
        ->find($id);

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'الطب غير موجود'
        ], 404);
    }

    $statusMeaning = $this->getStatusMeaning($order->status);

   $products = $order->items->map(function ($item) {
    return [
        'product' => $item->product, // يتوي على جميع بيانت المنتج كاملة
        'quantity' => $item->quantity,
        'original_price' => $item->original_price,
        'price_at_time' => $item->price_at_time,
        'total_price' => $item->total_price,
        // The size label saved at checkout; older orders fall back to the current size.
        'size' => $item->size_label ?? $item->size->size ?? 'غير متوفر',
        'product_name' => (app()->getLocale() === 'ar' ? $item->product_name_ar : $item->product_name_en) ?? $item->product?->name,
    ];
});


    return response()->json([
        'success' => true,
        'order_id' => $order->id,
        'status' => $order->status,
        'status_meaning' => $statusMeaning,
        'payment_method' => $order->payment_method,
        'delivery_fee' => $order->delivery_fee,
        'total_price' => $order->total_price,
        'points_earned' => $order->points_earned,
        'gift_description' => $order->gift_description,
        'address' => [
            'city' => $order->address->city ?? '-',
            'street' => $order->address->street ?? '-',
            'building' => $order->address->building ?? '-',
            'apartment' => $order->address->apartment ?? '-',
            'landmark' => $order->address->landmark ?? '-',
        ],
        'products' => $products,
        'created_at' => $order->created_at->format('Y-m-d H:i'),
        'last_update' => $order->updated_at->format('Y-m-d H:i'),
    ]);
}


public function groupedByStatus(Request $request)
{
    $user = auth()->user(); // و auth('sanctum')->user() لو API تستخدم Sanctum

    $orders = \App\Models\Order::withSum('items', 'quantity')

        ->where('user_id', $user->id)

        ->orderByDesc('created_at')
        ->get();

    $grouped = $orders->groupBy('status')->map(function ($orders) {
        return $orders->map(function ($order) {
            return [
                'order_id'     => $order->id,
                'status'       => $order->status,
                'total_price'  => $order->total_price,
                'items_count'  => $order->items_sum_quantity,
                'date'         => $order->created_at->format('Y-m-d H:i'),
            ];
        });
    });

    return response()->json([
        'success' => true,
        'data' => $grouped
    ]);
}





}
