<?php
namespace App\Modules\Orders;

use Carbon\Carbon;

use App\Models\ProductSize;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Models\{Order, OrderItem, Cart, Address, DeliveryPrice, Setting, DiscountRule};
use App\Models\ReturnRequest;
use App\Models\AdminAuditLog;
use App\Support\Money;
use App\Modules\Orders\Services\ReturnDecisionService;
use DomainException;

class ReturnRequestController extends Controller
{



public function store(Request $request)
{
    $request->validate([
        'order_id' => 'required|integer',
        'reason' => 'required|string|max:255',
        'note' => 'nullable|string|max:2000',
        'refund_amount' => 'nullable|numeric|min:0',
        'refund_method' => 'nullable|string|max:255',
        'address' => 'nullable|string|max:255',
        'images' => 'nullable|array|max:10',
        'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:2048',
    ]);

    // A return can only be requested for one of the caller's own orders.
    // An order with an accepted (partial) return is hidden from the order list, but more of it
    // may still be returned (decision D2: several partial refunds), up to what was paid.
    $order = Order::where('user_id', auth()->id())->find($request->order_id);

    if (!$order) {
        return response()->json(['message' => api_trans('returns.order_not_found')], 404);
    }

    // A canceled order was never delivered or paid for, so there is nothing to return.
    if ($order->status === 'canceled') {
        return response()->json(['message' => api_trans('returns.canceled_order'), 'code' => 'order_canceled'], 422);
    }

    // Only units that left the warehouse can come back: an order still being prepared is canceled, not returned.
    if (!\App\Modules\Orders\Services\ReturnDecisionService::hasDispatchedUnits($order)) {
        return response()->json(['message' => api_trans('returns.not_delivered'), 'code' => 'order_not_delivered'], 422);
    }

    // Return window (settings.return_window_days). Unset = no time limit, as before.
    if ($windowDays = Setting::current()->returnWindowDays()) {
        if ($order->status !== 'completed') {
            return response()->json(['message' => api_trans('returns.not_completed'), 'code' => 'order_not_completed'], 422);
        }
        if (($order->completed_at ?? $order->updated_at)->copy()->addDays($windowDays)->isPast()) {
            return response()->json(['message' => api_trans('returns.window_closed', ['days' => $windowDays]), 'code' => 'return_window_closed'], 422);
        }
    }

    // All refunds requested for an order (pending or accepted) cannot exceed what was paid.
    // Accepted requests count with the amount the admin approved.
    $alreadyRequestedMinor = ReturnRequest::where('order_id', $order->id)
        ->whereIn('status', ['pending', 'accepted'])
        ->get(['status', 'refund_amount', 'approved_amount'])
        ->sum(fn ($r) => Money::toMinor($r->status === 'accepted' ? ($r->approved_amount ?? $r->refund_amount) : $r->refund_amount));

    if ($request->filled('refund_amount')
        && $alreadyRequestedMinor + Money::toMinor($request->refund_amount) > Money::toMinor($order->total_price)) {
        return response()->json([
            'message' => api_trans('returns.exceeds_total'),
            'errors' => ['refund_amount' => ['The refund amount may not be greater than the order total.']],
        ], 422);
    }

    $return = ReturnRequest::create([
        'user_id' => auth()->id(),
        'order_id' => $request->order_id,
        'reason' => $request->reason,
        'note' => $request->note,
        'refund_amount' => $request->refund_amount,
        'refund_method' => $request->refund_method,
        'address' => $request->address,
    ]);

    if ($request->hasFile('images')) {
        foreach ($request->file('images') as $image) {
            // Private disk: served only through an authorised route.
            $path = $image->store('return_images', 'local');
            $return->images()->create(['path' => $path]);
        }
    }

    return response()->json(['message' => api_trans('returns.created'), 'data' => $return], 201);
}

public function index()
{
    $returns = ReturnRequest::with('order')
        ->where('user_id', auth()->id())
        ->select('id', 'order_id', 'reason', 'status', 'refund_amount', 'created_at')
        ->get();

    return response()->json($returns);
}

public function show($id)
{
    $return = ReturnRequest::with(['order', 'images'])
        ->where('user_id', auth()->id())
        ->findOrFail($id);

    return response()->json($return);
}


public function cancel($id)
{
    $return = ReturnRequest::where('user_id', auth()->id())->findOrFail($id);
    if ($return->status !== 'pending') {
        return response()->json(['message' => api_trans('returns.cannot_cancel')], 400);
    }

    $return->update(['status' => 'canceled']);

    return response()->json(['message' => api_trans('returns.canceled')]);
}


////// admin



 public function adminindex(Request $request)
    {
        $status = $request->get('status');

        $refunds = ReturnRequest::with(['user', 'order'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('dashboard.returns.index', compact('refunds'));
    }

    public function adminshow($id)
    {
        $refund = ReturnRequest::with(['user', 'order.items.product', 'order.items.returnItems.returnRequest', 'images', 'items', 'pointReversal'])->findOrFail($id);

        // Money already refunded on the order by other accepted returns (caps what can be approved here).
        $refundedBefore = Money::fromMinor(
            ReturnRequest::where('order_id', $refund->order_id)->where('status', 'accepted')->whereKeyNot($refund->id)
                ->get(['approved_amount', 'refund_amount'])
                ->sum(fn ($r) => Money::toMinor($r->approved_amount ?? $r->refund_amount))
        );

        return view('dashboard.returns.show', compact('refund', 'refundedBefore'));
    }

    /**
     * Accept = approve the refund, put the units received back into quarantine and reverse
     * loyalty points proportionally (see ReturnDecisionService). Never restocks.
     */
    public function accept(Request $request, $id, ReturnDecisionService $decisions)
    {
        $request->validate([
            'approved_amount' => 'nullable|numeric|min:0',
            'quantities' => 'nullable|array',
            'quantities.*' => 'nullable|integer|min:0',
        ]);

        try {
            $return = $decisions->accept((int) $id, $request->input('approved_amount'), $request->input('quantities', []), auth('admin')->id());
            AdminAuditLog::record('return.accept', $return, ['approved_amount' => $return->approved_amount]);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Return accepted. Returned units are in quarantine, not back on sale.');
    }

    public function reject(Request $request, $id, ReturnDecisionService $decisions)
    {
        $request->validate(['reason' => 'nullable|string|max:2000']);

        try {
            $return = $decisions->reject((int) $id, $request->reason, auth('admin')->id());
            AdminAuditLog::record('return.reject', $return);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Return rejected.');
    }
}
