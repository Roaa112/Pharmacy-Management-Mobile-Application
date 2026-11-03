<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\Order;
use App\Modules\Points\Services\LoyaltyService;
use Illuminate\Http\Request;

/**
 * Usable points per order, and the "Redeem" button for points a customer uses in the pharmacy.
 * The wider programme (customers, transactions, rewards, settings) is LoyaltyDashboardController.
 */
class PointController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function index(Request $request)
    {
        $query = Entry::usable()->whereNotNull('order_id')->where('type', Entry::EARNED)
            ->with('user:id,name')
            ->when($request->filled('user_name'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->user_name.'%')))
            ->when($request->filled('order_id'), fn ($q) => $q->where('order_id', (int) $request->order_id))
            ->latest('id');

        // Totals in SQL; only one page of lots is loaded.
        $total = (int) Entry::usable()->sum('remaining_points');
        $page = $query->paginate(50)->withQueryString();
        $breakdown = $this->loyalty->lotBreakdown(collect($page->items())->pluck('id')->all());

        $data = collect($page->items())->map(fn (Entry $lot) => [
            'order_id' => $lot->order_id,
            'user_name' => $lot->user?->name ?? '-',
            'display_id' => "طلب #{$lot->order_id}",
            'points_earned' => (int) $lot->remaining_points,
            'points_reversed' => $breakdown[$lot->id]['reversed'],
            'created_at' => ($lot->earned_at ?? $lot->occurred_at)->toDateTimeString(),
            'expires_at' => $lot->expires_at?->toDateTimeString(),
        ]);

        return view('dashboard.Points.index', [
            'valid_points_total' => $total,
            'orders' => $data,
            'paginator' => $page,
        ]);
    }

    public function redeemPoints(Order $order)
    {
        if (!$this->loyalty->redeem([$order->id], auth('admin')->id())) {
            return redirect()->back()->with('error', __('points.cannot_redeem', [], 'ar'));
        }

        AdminAuditLog::record('points.redeem', $order);

        return redirect()->back()->with('success', __('points.redeemed', [], 'ar'));
    }

    public function redeemMultiple(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|max:500',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $redeemed = $this->loyalty->redeem($request->order_ids, auth('admin')->id());
        AdminAuditLog::record('points.redeem_multiple', null, ['order_ids' => $request->order_ids, 'redeemed' => $redeemed]);

        return redirect()->back()->with('success', __('points.redeemed_many', ['count' => $redeemed], 'ar'));
    }
}
