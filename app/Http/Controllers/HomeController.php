<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Reports\Revenue;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function __construct()
    {
        // Admins use the "admin" guard; the default "web" guard would bounce them to the login page.
        $this->middleware('auth:admin');
    }

    public function home()
    {
        $lowStock = Setting::current()->lowStockThreshold();
        $from = now()->subDays(29)->startOfDay();

        $stats = [
            // Revenue: completed orders minus approved refunds (same rule as the orders page and reports).
            'revenue' => Revenue::net(Order::query()),
            'orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'customers' => User::count(),
            'products' => Product::count(),
            'low_stock' => $this->sellableSizes()->whereBetween('stock', [1, $lowStock])->count(),
            'out_of_stock' => $this->sellableSizes()->where('stock', '<=', 0)->count(),
            'pending_returns' => ReturnRequest::where('status', 'pending')->count(),
            'coupon_orders' => Order::whereNotNull('coupon_id')->where('status', '!=', 'canceled')->count(),
        ];

        $trend = $this->dailyTrend($from);

        $recentOrders = Order::with('user:id,name')->latest()->limit(6)->get();

        // Units sold, ignoring canceled orders.
        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', 'canceled')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as units'), DB::raw('SUM(order_items.total_price) as sales'))
            ->groupBy('order_items.product_id')
            ->orderByDesc('units')
            ->limit(5)
            ->with('product')
            ->get();

        $lowStockSizes = $this->sellableSizes()
            ->where('stock', '<=', $lowStock)
            ->with('product:id,name_ar,name_en,image')
            ->orderBy('stock')
            ->limit(6)
            ->get();

        return view('dashboard.index', compact('stats', 'trend', 'recentOrders', 'topProducts', 'lowStockSizes', 'lowStock'));
    }

    /**
     * Sizes of products that are still listed (not deleted).
     */
    private function sellableSizes()
    {
        return ProductSize::query()->whereHas('product');
    }

    /**
     * Orders per day and net completed revenue per local day, every day filled in.
     */
    private function dailyTrend($from): array
    {
        $orders = Order::where('created_at', '>=', $from)
            ->selectRaw(Revenue::localDate('created_at').' as day, COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('orders', 'day');

        $revenue = Revenue::completed(Order::where('created_at', '>=', $from))
            ->selectRaw(Revenue::localDate('orders.created_at').' as day, '.Revenue::NET_SUM.' as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $trend = ['labels' => [], 'orders' => [], 'revenue' => []];

        foreach (CarbonPeriod::create($from, now()->startOfDay()) as $day) {
            $key = $day->toDateString();
            $trend['labels'][] = $day->format('M j');
            $trend['orders'][] = (int) ($orders[$key] ?? 0);
            $trend['revenue'][] = round((float) ($revenue[$key] ?? 0), 2);
        }

        return $trend;
    }
}
