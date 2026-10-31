<?php

namespace App\Modules\Customers;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Read-only customer pages. Health records are deliberately not shown here.
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $customers = User::query()
            ->select('id', 'name', 'email', 'phone', 'created_at')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->withCount(['orders' => fn ($q) => $q->where('status', '!=', 'canceled')])
            ->withSum(['orders as spent' => fn ($q) => $q->where('status', 'completed')], 'total_price')
            ->withMax('orders as last_order_at', 'created_at')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('dashboard.customers.index', [
            'customers' => $customers,
            'search' => $search,
            'total' => User::count(),
            'newThisMonth' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'withOrders' => User::whereHas('orders')->count(),
        ]);
    }

    public function show(User $customer)
    {
        $orders = Order::where('user_id', $customer->id)
            ->withCount('items')
            ->latest()
            ->paginate(10);

        $summary = [
            'orders' => Order::where('user_id', $customer->id)->count(),
            'spent' => Order::where('user_id', $customer->id)->where('status', 'completed')->sum('total_price'),
            // Same validity rule as the loyalty points page.
            'points' => Order::where('user_id', $customer->id)
                ->where('points_status', 'earned')
                ->where('status', '!=', 'canceled')
                ->where('created_at', '>=', now()->subDays(Setting::first()?->points_validity_days ?? 0))
                ->withSum('pointReversals as points_reversed', 'points')
                ->get(['id', 'points_earned'])
                ->sum(fn ($order) => max(0, (int) $order->points_earned - (int) $order->points_reversed)),
            // Points reversed by a refund after the customer had already redeemed them (decision D2).
            'points_owed' => (int) LoyaltyPointReversal::where('user_id', $customer->id)
                ->where('points_status_at_reversal', 'redeemed')
                ->sum('points'),
            'returns' => $customer->returnRequests()->count(),
        ];

        return view('dashboard.customers.show', [
            'customer' => $customer,
            'addresses' => $customer->addresses()->latest()->get(),
            'orders' => $orders,
            'summary' => $summary,
        ]);
    }
}
