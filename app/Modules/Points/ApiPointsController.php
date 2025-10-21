<?php

namespace App\Modules\Points;

use App\Models\Order;
use App\Models\Product;

use App\Models\Setting;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Modules\Product\Services\ProductService;
use App\Modules\Product\Repositories\ProductsRepository;
use App\Modules\Product\Requests\ListAllProductsRequest;


class ApiPointsController extends Controller
{
    /**
     * نقاط صالحة حسب المدة المحددة من الأدمن
     */
    public function getValidPoints(Request $request)
    {
        $user = $request->user();
        $validityDays = Setting::first()?->points_validity_days ?? 0;
        \Log::info('Points validity days = ' . $validityDays);

        $validFromDate = now()->subDays($validityDays);

        $orders = Order::where('user_id', $user->id)
            ->whereNotNull('points_earned')
            ->where('points_earned', '>', 0)
                ->where('points_status', 'earned')
            ->where('created_at', '>=', $validFromDate)
            ->get();

        $data = $orders->map(function ($order) use ($validityDays) {
            return [
                'order_id' => $order->id,
                'display_id' => $order->display_id,
                'points_earned' => $order->points_earned,
                'created_at' => $order->created_at->toDateTimeString(),
                'expires_at' => $order->created_at->copy()->addDays($validityDays)->toDateTimeString(),
            ];
        });

        return response()->json([
            'valid_points_total' => $orders->sum('points_earned'),
            'orders' => $data,
        ]);
    }

    public function getExpiredPoints(Request $request)
    {
        $user = $request->user();
        $validityDays = Setting::first()?->points_validity_days ?? 0;

        $validUntilDate = now()->subDays($validityDays);

        $orders = Order::where('user_id', $user->id)
            ->whereNotNull('points_earned')
            ->where('points_earned', '>', 0)
                ->where('points_status', 'earned')
            ->where('created_at', '<', $validUntilDate)
            ->get();

        $data = $orders->map(function ($order) use ($validityDays) {
            return [
                'order_id' => $order->id,
                'display_id' => $order->display_id,
                'points_earned' => $order->points_earned,
                'created_at' => $order->created_at->toDateTimeString(),
                'expired_at' => $order->created_at->copy()->addDays($validityDays)->toDateTimeString(),
            ];
        });

        return response()->json([
            'expired_points_total' => $orders->sum('points_earned'),
            'orders' => $data,
        ]);
    }
    public function getConsumedPoints(Request $request)
{
    $user = $request->user();

    // جلب الطلبات اللي تم استهلاك نقاطها
    $orders = Order::where('user_id', $user->id)
        ->whereNotNull('points_earned')
        ->where('points_earned', '>', 0)
        ->where('points_status', 'redeemed') // ✅ الطلبات اللي اتعملها استبدال
        ->get();

    $data = $orders->map(function ($order) {
        return [
            'order_id' => $order->id,
            'display_id' => $order->display_id,
            'points_earned' => $order->points_earned,
            'created_at' => $order->created_at->toDateTimeString(),
            'redeemed_at' => $order->updated_at->toDateTimeString(), // وقت الاستبدال
        ];
    });

    return response()->json([
        'consumed_points_total' => $orders->sum('points_earned'),
        'orders' => $data,
    ]);
}

}
