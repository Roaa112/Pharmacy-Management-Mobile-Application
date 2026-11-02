<?php
namespace App\Modules\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\Setting;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Orders\Requests\CheckoutRequest;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Revenue;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private OrderWorkflow $workflow)
    {
    }

    public function points(Request $request)
    {
        $orders = Order::visibleToCustomer()
            ->where('user_id', $request->user()->id)
            ->select('id as order_name', 'points_earned', 'points_status', 'created_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function checkout(CheckoutRequest $request, CheckoutService $checkout)
    {
        return $checkout->checkout($request->user(), $request->validated(), $request->paymentMethod(), $request->file('payment_image'));
    }

    /** Customer cancellation of a pending order, when the store allows it (settings, decision D4). */
    public function cancel(Request $request, $id)
    {
        $order = Order::visibleToCustomer()->where('user_id', $request->user()->id)->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => api_trans('orders.not_found')], 404);
        }

        if (!Setting::current()->customer_can_cancel_pending || $order->status !== OrderStatus::Pending->value) {
            return response()->json(['success' => false, 'message' => api_trans('orders.cancel_not_allowed'), 'code' => 'cancel_not_allowed'], 422);
        }

        try {
            // Pending is re-checked under the order lock: the warehouse may have moved it on since the read above.
            $order = $this->workflow->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_CUSTOMER, $request->user()->id, onlyFrom: [OrderStatus::Pending]);
        } catch (DomainException) {
            return response()->json(['success' => false, 'message' => api_trans('orders.cancel_not_allowed'), 'code' => 'cancel_not_allowed'], 422);
        }

        return response()->json(['success' => true, 'message' => api_trans('orders.canceled'), 'status' => $order->status]);
    }

    public function updateStatus(Request $request, Order $order, OrderFulfilment $fulfilment)
    {
        $request->validate([
            'status' => ['required', Rule::in(OrderStatus::values())],
        ]);

        try {
            $fulfilment->orderStatusFromDashboard($order, OrderStatus::from($request->status), auth('admin')->id());
        } catch (DomainException) {
            return redirect()->back()->with('error', __('orders.transition_refused', ['from' => $order->status, 'to' => $request->status]));
        }

        AdminAuditLog::record('order.status', $order, ['from' => $order->status, 'to' => $request->status]);

        return redirect()->back()->with('success', __('orders.status_updated'));
    }

    /**
     * Verify the transfer receipt of a legacy manual-transfer order (ZainCash / Qi Card, placed
     * before those methods were retired). Online payments can never be marked paid by hand, and
     * COD is collected through delivery or completion; refunds go through the Refunds screen.
     */
    public function updatePaymentStatus(Request $request, Order $order, PaymentService $payments)
    {
        $request->validate(['payment_status' => ['required', Rule::in([PaymentStatus::Paid->value, PaymentStatus::Failed->value])]]);

        $payment = $order->payments()->where('status', PaymentStatus::AwaitingVerification->value)->latest('id')->first();

        try {
            if (!$payment) {
                throw new DomainException('There is no receipt waiting for verification on this order.');
            }
            $payments->verifyManualReceipt($payment, $request->payment_status === PaymentStatus::Paid->value, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('order.payment_status', $order, ['payment' => $payment->internal_reference, 'from' => PaymentStatus::AwaitingVerification->value, 'to' => $request->payment_status]);

        return redirect()->back()->with('success', 'Payment status updated.');
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $orders = Order::with(['user:id,name,phone', 'returnRequests:id,order_id,status'])
            ->withCount('items')
            ->when(in_array($status, OrderStatus::values(), true), fn ($q) => $q->where('status', $status))
            ->when($request->query('payment_status'), fn ($q, $paymentStatus) => $q->where('payment_status', $paymentStatus))
            // Any method, retired ones included, so historical orders (e.g. ZainCash) stay findable.
            ->when(is_string($request->query('payment_method')), fn ($q) => $q->where('payment_method', $request->query('payment_method')))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    if (ctype_digit(ltrim($search, '#'))) {
                        $q->where('id', (int) ltrim($search, '#'));
                    }
                    $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $statusCounts = Order::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

        return view('dashboard.orders.index', [
            'orders' => $orders,
            'totalEarned' => Revenue::net(Order::query()),
            'statusCounts' => $statusCounts,
            'statusLabels' => $this->statusLabels(),
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function show(Order $order)
    {
        $order->load([
            'user:id,name,email,phone',
            'address',
            'items.product' => fn ($q) => $q->basic(),
            'items.size',
            'giftRule',
            'coupon:id,code,discount_value',
            'returnRequests',
            'shipments.provider',
            'shipments.events',
            'statusHistory',
            'paymentVerifier:id,name',
            'paymentMethod',
            'payments',
            'refunds',
            'items.reservations.warehouse:id,code',
        ]);

        // Only the transitions the server will accept are offered (see OrderStatus::canTransitionTo()).
        $nextStatuses = collect($this->statusLabels())
            ->filter(fn ($label, $value) => $order->canTransitionTo($value));

        return view('dashboard.orders.show', [
            'order' => $order,
            'statusLabels' => $this->statusLabels(),
            'nextStatuses' => $nextStatuses,
            'providers' => DeliveryProvider::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    private function statusLabels(): array
    {
        return collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label('en')])->all();
    }

    public function track($id)
    {
        $order = Order::visibleToCustomer()->with('latestShipment.events', 'latestShipment.provider')->where('user_id', auth()->id())->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => api_trans('orders.not_found')], 404);
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            // Apps know five statuses: the warehouse steps (picking, packed) are reported as "confirmed".
            'status' => $order->statusEnum()->customerStatus()->value,
            'status_meaning' => $this->getStatusMeaning($order->status),
            'last_update' => $order->updated_at->format('Y-m-d H:i'),
            // Added fields.
            'status_label' => OrderStatus::from($order->status)->label(api_locale()),
            'fulfillment_stage' => $order->status,
            'payment_status' => $order->payment_status,
            'delivery' => $order->latestShipment?->toCustomerArray(),
        ]);
    }

    private function getStatusMeaning($status)
    {
        return OrderStatus::tryFrom((string) $status)?->legacyMeaning() ?? __('orders.status_meaning.unknown', [], 'ar');
    }

    public function details($id)
    {
        $order = Order::visibleToCustomer()
            ->with(['user', 'address', 'items.product.productImages', 'items.size', 'latestShipment.events', 'latestShipment.provider'])
            ->where('user_id', auth()->id())
            ->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => api_trans('orders.not_found')], 404);
        }

        $products = $order->items->map(fn ($item) => [
            'product' => $item->product,
            'quantity' => $item->quantity,
            'original_price' => $item->original_price,
            'price_at_time' => $item->price_at_time,
            'total_price' => $item->total_price,
            // The size label saved at checkout; older orders fall back to the current size.
            'size' => $item->size_label ?? $item->size->size ?? __('orders.size_unavailable', [], 'ar'),
            'product_name' => (app()->getLocale() === 'ar' ? $item->product_name_ar : $item->product_name_en) ?? $item->product?->name,
            'is_gift' => (bool) $item->is_gift,
            // Added: the promotion snapshot of the line (gift reward, or the discounts that set its price).
            'discount_amount' => $item->discount_amount,
            'promotion' => $item->promotion_snapshot,
        ]);

        // The address as it was at checkout, not as the customer may have edited it since.
        $address = $order->deliveryAddress() ?? [];

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            // Apps know five statuses: the warehouse steps (picking, packed) are reported as "confirmed".
            'status' => $order->statusEnum()->customerStatus()->value,
            'status_meaning' => $this->getStatusMeaning($order->status),
            'payment_method' => $order->payment_method,
            'delivery_fee' => $order->delivery_fee,
            'total_price' => $order->total_price,
            'points_earned' => $order->points_earned,
            'gift_description' => $order->gift_description,
            'address' => [
                'city' => $address['city'] ?? '-',
                'street' => $address['street'] ?? '-',
                'building' => $address['building'] ?? '-',
                'apartment' => $address['apartment'] ?? '-',
                'landmark' => $address['landmark'] ?? '-',
            ],
            'products' => $products,
            'created_at' => $order->created_at->format('Y-m-d H:i'),
            'last_update' => $order->updated_at->format('Y-m-d H:i'),
            // Added fields.
            'status_label' => OrderStatus::from($order->status)->label(api_locale()),
            'fulfillment_stage' => $order->status,
            'payment_status' => $order->payment_status,
            'refund_status' => $order->refund_status,
            'subtotal' => $order->subtotal,
            'discount' => $order->discount_amount,
            'items_discount' => $order->items_discount_amount,
            'coupon_discount' => $order->coupon_discount_amount,
            'promotion_discount' => $order->promotion_discount_amount,
            'delivery_discount' => $order->delivery_discount_amount,
            'total_savings' => $order->total_savings,
            'coupon_code' => $order->coupon_code,
            'promotions' => $order->promotions()->get(['source', 'promotion_type', 'name', 'code', 'discount_amount', 'delivery_discount_amount', 'reward_quantity']),
            'currency' => $order->currency_code,
            'can_cancel' => Setting::current()->customer_can_cancel_pending && $order->status === OrderStatus::Pending->value,
            'delivery' => $order->latestShipment?->toCustomerArray(),
        ]);
    }

    public function groupedByStatus(Request $request)
    {
        $orders = Order::visibleToCustomer()
            ->withSum('items', 'quantity')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        $grouped = $orders->groupBy(fn ($order) => $order->statusEnum()->customerStatus()->value)->map(fn ($orders) => $orders->map(fn ($order) => [
            'order_id' => $order->id,
            'status' => $order->statusEnum()->customerStatus()->value,
            'fulfillment_stage' => $order->status,
            'total_price' => $order->total_price,
            'items_count' => $order->items_sum_quantity,
            'date' => $order->created_at->format('Y-m-d H:i'),
        ]));

        return response()->json(['success' => true, 'data' => $grouped]);
    }
}
