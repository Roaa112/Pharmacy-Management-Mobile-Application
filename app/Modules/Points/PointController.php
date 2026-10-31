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


class PointController extends Controller
{
   public function index(Request $request)
{
    // جلب المدة المحددة من الإعدادات
    $validityDays = Setting::first()?->points_validity_days ?? 0;

    // لو المدة صفر يبقى مش هيحسب صلاحية
    $validFromDate = now()->subDays($validityDays);

    // جلب الطلبات الصالحة
    $query = Order::whereNotNull('points_earned')
        ->where('points_earned', '>', 0)
        ->where('points_status', 'earned') // ✅ لسه ما استبدلتش
        ->where('status', '!=', 'canceled')
        ->where('created_at', '>=', $validFromDate);

    // ✅ فلتر بالاسم لو موجود
    if ($request->filled('user_name')) {
        $query->whereHas('user', function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->user_name . '%');
        });
    }

    // ✅ فلتر برقم الأوردر لو موجود
    if ($request->filled('order_id')) {
        $query->where('id', $request->order_id);
    }

    // Points reversed by refunds (decision D2) are taken off; fully reversed orders drop out.
    $orders = $query->with('user')->withSum('pointReversals as points_reversed', 'points')->get()
        ->each(fn ($order) => $order->points_net = (int) $order->points_earned - (int) $order->points_reversed)
        ->filter(fn ($order) => $order->points_net > 0)
        ->values();

    // تجهيز البيانات للعرض
    $data = $orders->map(function ($order) use ($validityDays) {
        return [
            'order_id' => $order->id,
            'user_name' => $order->user?->name ?? '-', // يظهر اسم العميل
            'display_id' => $order->display_id,
            'points_earned' => $order->points_net,
            'points_reversed' => (int) $order->points_reversed,
            'created_at' => $order->created_at->toDateTimeString(),
            'expires_at' => $order->created_at->copy()->addDays($validityDays)->toDateTimeString(),
        ];
    });

     return  view('dashboard.Points.index', [
           'valid_points_total' => $orders->sum('points_net'),
            'orders' => $data,
        ]);
}

    public function redeemPoints(Order $order)
    {
        // Conditional update: only valid, unredeemed points change, so a repeated click does nothing.
        $redeemed = $this->redeemableOrders()->whereKey($order->id)->update(['points_status' => 'redeemed']);

        if (!$redeemed) {
            return redirect()->back()->with('error', 'لا يمكن استبدال نقاط هذا الطلب (مستبدلة أو منتهية أو طلب ملغي).');
        }

        return redirect()->back()->with('success', 'تم استبدال النقاط لهذا الطلب بنجاح.');
    }
    public function redeemMultiple(Request $request)
{
    $request->validate([
        'order_ids' => 'required|array',
        'order_ids.*' => 'exists:orders,id',
    ]);

    $redeemed = $this->redeemableOrders()->whereIn('id', $request->order_ids)
        ->update(['points_status' => 'redeemed']);

    return redirect()->back()->with('success', "تم استبدال النقاط لـ {$redeemed} من الأوردرات المحددة.");
}

    /**
     * Same eligibility as the points list: earned, not redeemed, not canceled, within validity.
     */
    private function redeemableOrders()
    {
        $validityDays = Setting::first()?->points_validity_days ?? 0;

        return Order::where('points_earned', '>', 0)
            ->where('points_status', 'earned')
            ->where('status', '!=', 'canceled')
            ->where('created_at', '>=', now()->subDays($validityDays))
            // Nothing left to redeem once refunds reversed all of the order's points.
            ->whereRaw('orders.points_earned > (select coalesce(sum(points), 0) from loyalty_point_reversals where loyalty_point_reversals.order_id = orders.id)');
    }

}
