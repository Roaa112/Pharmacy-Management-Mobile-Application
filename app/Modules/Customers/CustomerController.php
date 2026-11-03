<?php

namespace App\Modules\Customers;

use App\Modules\Points\Services\LoyaltyService;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
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

        $loyalty = app(LoyaltyService::class)->summary($customer->id);

        $summary = [
            'orders' => Order::where('user_id', $customer->id)->count(),
            'spent' => Order::where('user_id', $customer->id)->where('status', 'completed')->sum('total_price'),
            // Same numbers as the loyalty points page and the customer app (LoyaltyService).
            'points' => $loyalty['available'],
            // Points reversed by a refund after the customer had already redeemed them (decision D2).
            'points_owed' => $loyalty['owed'],
            'returns' => $customer->returnRequests()->count(),
        ];

        $filters = request()->validate([
            'points_type' => ['nullable', \Illuminate\Validation\Rule::in(LoyaltyLedgerEntry::TYPES)],
            'points_from' => ['nullable', 'date'],
            'points_to' => ['nullable', 'date'],
        ]);
        $canSeeLoyalty = auth('admin')->user()?->can('loyalty.view');

        return view('dashboard.customers.show', [
            'customer' => $customer,
            'addresses' => $customer->addresses()->latest()->get(),
            'orders' => $orders,
            'summary' => $summary,
            'loyalty' => $loyalty,
            'pointEntries' => $canSeeLoyalty ? LoyaltyLedgerEntry::with('admin:id,name')->where('user_id', $customer->id)
                ->when($filters['points_type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->when($filters['points_from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay()))
                ->when($filters['points_to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay()))
                ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15, ['*'], 'points_page')->withQueryString() : collect(),
            'rewards' => $canSeeLoyalty ? LoyaltyReward::available()->orderBy('points_required')->get() : collect(),
            'redemptions' => $canSeeLoyalty ? LoyaltyRedemption::with('reward:id,title')->where('user_id', $customer->id)->latest('id')->limit(8)->get() : collect(),
        ]);
    }

    /**
     * Deactivate or reactivate a customer. A deactivated customer cannot log in and their tokens
     * stop working; their orders and history stay (customers are never deleted).
     */
    public function toggleActive(User $customer)
    {
        $active = !$customer->isActive();
        $customer->forceFill(['is_active' => $active])->save();

        if (!$active) {
            $customer->tokens()->delete();
        }

        AdminAuditLog::record($active ? 'customer.activate' : 'customer.deactivate', $customer);

        return redirect()->back()->with('success', $active ? 'Customer reactivated.' : 'Customer deactivated and signed out.');
    }
}
