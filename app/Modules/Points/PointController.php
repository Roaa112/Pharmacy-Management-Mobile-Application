<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Modules\Points\Services\LoyaltyService;
use Illuminate\Http\Request;

class PointController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function index(Request $request)
    {
        $query = $this->loyalty->valid()
            ->with('user:id,name')
            ->when($request->filled('user_name'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->user_name.'%')))
            ->when($request->filled('order_id'), fn ($q) => $q->where('orders.id', $request->order_id))
            ->latest('orders.created_at');

        // Totals in SQL; only one page of orders is loaded.
        $total = $this->loyalty->netTotal($this->loyalty->valid());
        $page = $query->paginate(50)->withQueryString();

        $data = collect($page->items())->map(fn (Order $order) => [
            'order_id' => $order->id,
            'user_name' => $order->user?->name ?? '-',
            'display_id' => $order->display_id,
            'points_earned' => LoyaltyService::net($order),
            'points_reversed' => (int) $order->points_reversed,
            'created_at' => $order->created_at->toDateTimeString(),
            'expires_at' => $this->loyalty->expiresAt($order)->toDateTimeString(),
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
