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

    $orders = $query->with('user')->get();

    // تجهيز البيانات للعرض
    $data = $orders->map(function ($order) use ($validityDays) {
        return [
            'order_id' => $order->id,
            'user_name' => $order->user?->name ?? '-', // يظهر اسم العميل
            'display_id' => $order->display_id,
            'points_earned' => $order->points_earned,
            'created_at' => $order->created_at->toDateTimeString(),
            'expires_at' => $order->created_at->copy()->addDays($validityDays)->toDateTimeString(),
        ];
    });

     return  view('dashboard.Points.index', [
           'valid_points_total' => $orders->sum('points_earned'),
            'orders' => $data,
        ]);
}

    public function redeemPoints(Order $order)
    {
        $order->update(['points_status' => 'redeemed']);

        return redirect()->back()->with('success', 'تم استبدال النقاط لهذا الطلب بنجاح.');
    }
    public function redeemMultiple(Request $request)
{
    $request->validate([
        'order_ids' => 'required|array',
        'order_ids.*' => 'exists:orders,id',
    ]);

    Order::whereIn('id', $request->order_ids)
        ->update(['points_status' => 'redeemed']);

    return redirect()->back()->with('success', 'تم استبدال النقاط للأوردرات المحددة بنجاح.');
}

}
