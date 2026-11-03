<?php

namespace App\Modules\Reports;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business reports calculated from orders, returns and stock.
 * Revenue = completed orders minus the refunds approved on them (see Revenue).
 * Days are the store's local calendar days (PHARMACY_TIMEZONE), not UTC days.
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        $inRange = fn () => Order::whereBetween('created_at', [$from, $to]);
        $revenueOrders = fn () => Revenue::completed($inRange());

        $revenue = Revenue::net($inRange());
        $completed = $revenueOrders()->count();

        $summary = [
            'revenue' => $revenue,
            'orders' => $inRange()->count(),
            'completed' => $completed,
            'average_order' => $completed ? $revenue / $completed : 0,
            'canceled' => $inRange()->where('status', 'canceled')->count(),
            'new_customers' => User::whereBetween('created_at', [$from, $to])->count(),
        ];

        $statusCounts = $inRange()->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

        // Daily revenue in the range.
        $dailyRevenue = $revenueOrders()->selectRaw(Revenue::localDate('orders.created_at').' as day, '.Revenue::NET_SUM.' as revenue')->groupBy('day')->pluck('revenue', 'day');
        $daily = ['labels' => [], 'revenue' => []];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $daily['labels'][] = $day->format('M j');
            $daily['revenue'][] = round((float) ($dailyRevenue[$day->toDateString()] ?? 0), 2);
        }

        $monthly = $this->monthly();

        $topProducts = $this->productSales($from, $to)->orderByDesc('units')->limit(10)->get();

        // In-stock sizes that sold nothing in the range.
        $slowMoving = ProductSize::query()
            ->where('stock', '>', 0)
            ->whereHas('product')
            ->whereNotIn('id', OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.created_at', [$from, $to])
                ->where('orders.status', '!=', 'canceled')
                ->select('order_items.product_size_id'))
            ->with(['product' => fn ($q) => $q->basic()->select('id', 'name_en')])
            ->orderByDesc('stock')
            ->limit(10)
            ->get();

        $returns = ReturnRequest::whereBetween('created_at', [$from, $to])
            ->select('status', DB::raw('COUNT(*) as total'), DB::raw('COALESCE(SUM(COALESCE(approved_amount, refund_amount)), 0) as amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $coupons = Coupon::query()
            ->withCount(['orders' => fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'canceled')])
            ->withSum(['orders as orders_value' => fn ($q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'canceled')], 'total_price')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get();

        $loyalty = [
            // From the points ledger: what moved in the period, whatever its order.
            'earned' => (int) \App\Models\LoyaltyLedgerEntry::whereIn('type', ['earned', 'bonus'])->whereBetween('occurred_at', [$from, $to])->sum('points'),
            'redeemed' => -(int) \App\Models\LoyaltyLedgerEntry::whereIn('type', ['redeemed', 'cancelled'])->whereBetween('occurred_at', [$from, $to])->sum('points'),
            'reversed' => (int) LoyaltyPointReversal::whereBetween('created_at', [$from, $to])->sum('points'),
            'expired' => -(int) \App\Models\LoyaltyLedgerEntry::where('type', 'expired')->whereBetween('occurred_at', [$from, $to])->sum('points'),
        ];

        // Non-canceled orders per payment method, with what is still awaiting verification.
        $payments = $inRange()->where('status', '!=', 'canceled')
            ->select('payment_method', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total_price) as value'),
                DB::raw("SUM(CASE WHEN payment_status = 'awaiting_verification' THEN 1 ELSE 0 END) as to_verify"))
            ->groupBy('payment_method')->get();

        // Shipments of orders in the range: status counts, delivery fees charged and cash on delivery.
        $delivery = \App\Models\Shipment::whereIn('order_id', $inRange()->select('id'))
            ->select('status', DB::raw('COUNT(*) as total'), DB::raw('SUM(fee_charged) as fees'), DB::raw('SUM(cod_amount) as cod'))
            ->groupBy('status')->get();
        $codOutstanding = (float) \App\Models\Shipment::whereIn('order_id', $inRange()->select('id'))
            ->whereNotNull('cod_collected_at')->whereNull('cod_settled_at')->sum('cod_amount');

        return view('dashboard.reports.index', compact(
            'from', 'to', 'summary', 'statusCounts', 'daily', 'monthly', 'topProducts', 'slowMoving', 'returns', 'coupons', 'loyalty',
            'payments', 'delivery', 'codOutstanding'
        ));
    }

    private function productSales(Carbon $from, Carbon $to)
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'canceled')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as units'), DB::raw('SUM(order_items.total_price) as sales'))
            ->groupBy('order_items.product_id')
            ->with('product');
    }

    /**
     * Revenue and new customers for the last 12 calendar months.
     * Grouped by day in SQL (portable DATE()) and rolled up into months here.
     */
    private function monthly(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $revenueByDay = Revenue::completed(Order::where('created_at', '>=', $start))
            ->selectRaw(Revenue::localDate('orders.created_at').' as day, '.Revenue::NET_SUM.' as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $customersByDay = User::where('created_at', '>=', $start)
            ->selectRaw(Revenue::localDate('created_at').' as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$start->copy()->addMonths($i)->format('Y-m')] = ['revenue' => 0.0, 'customers' => 0];
        }
        foreach ($revenueByDay as $day => $amount) {
            $months[substr($day, 0, 7)]['revenue'] += (float) $amount;
        }
        foreach ($customersByDay as $day => $count) {
            $months[substr($day, 0, 7)]['customers'] += (int) $count;
        }

        return [
            'labels' => array_map(fn ($m) => Carbon::createFromFormat('Y-m', $m)->format('M Y'), array_keys($months)),
            'revenue' => array_map(fn ($m) => round($m['revenue'], 2), array_values($months)),
            'customers' => array_column($months, 'customers'),
        ];
    }
}
