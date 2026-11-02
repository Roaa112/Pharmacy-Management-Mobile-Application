<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ProductSize;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\PurchasingService;
use DomainException;
use Illuminate\Http\Request;

/** Purchase orders: draft -> ordered -> (partially) received -> closed. Only receipts change stock. */
class PurchaseOrderController extends Controller
{
    public function __construct(private PurchasingService $purchasing)
    {
    }

    public function index(Request $request)
    {
        $orders = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,code'])->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('supplier'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIALLY_RECEIVED])->whereDate('expected_at', '<', now()))
            ->latest('id')->paginate(25)->withQueryString();

        return view('dashboard.purchase-orders.index', [
            'orders' => $orders,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency_code']),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'sizes' => ProductSize::whereHas('product')->with(['product' => fn ($q) => $q->basic()->select('id', 'name_en')])->orderBy('product_id')->get(['id', 'product_id', 'size', 'sku', 'cost_price']),
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'expected_at' => 'nullable|date',
            'tax_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.product_size_id' => 'required|integer|exists:product_sizes,id',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);

        try {
            $order = $this->purchasing->createOrder(Supplier::findOrFail($data['supplier_id']), $data['warehouse_id'], $data['lines'],
                collect($data)->only(['expected_at', 'tax_amount', 'shipping_cost', 'notes'])->all(), auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('purchase_order.create', $order, ['total' => $order->total]);

        return redirect()->route('dashboard.purchase_orders.show', $order)->with('success', 'Purchase order drafted. Stock changes only when goods are received.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'creator:id,name', 'items.size.product' => fn ($q) => $q->basic()->withTrashed(),
            'receipts.items', 'receipts.receiver:id,name', 'invoices']);

        return view('dashboard.purchase-orders.show', ['order' => $purchaseOrder]);
    }

    public function markOrdered(PurchaseOrder $purchaseOrder)
    {
        return $this->run(fn () => $this->purchasing->markOrdered($purchaseOrder, auth('admin')->id()), 'purchase_order.ordered', 'Purchase order sent.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'lines' => 'required|array',
            'lines.*.received' => 'nullable|integer|min:0',
            'lines.*.damaged' => 'nullable|integer|min:0',
            'lines.*.lot_number' => 'nullable|string|max:64',
            'lines.*.expiry_date' => 'nullable|date',
            'supplier_invoice_number' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->run(fn () => $this->purchasing->receive($purchaseOrder, $data['lines'], $data['supplier_invoice_number'] ?? null, $data['notes'] ?? null, auth('admin')->id())
            ->purchaseOrder, 'purchase_order.receive', 'Goods received into stock.');
    }

    public function close(PurchaseOrder $purchaseOrder)
    {
        return $this->run(fn () => $this->purchasing->close($purchaseOrder), 'purchase_order.close', 'Purchase order closed; outstanding units are no longer expected.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        return $this->run(fn () => $this->purchasing->cancel($purchaseOrder), 'purchase_order.cancel', 'Purchase order canceled.');
    }

    private function run(callable $action, string $audit, string $message)
    {
        try {
            $order = $action();
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        AdminAuditLog::record($audit, $order, ['status' => $order->status]);

        return redirect()->route('dashboard.purchase_orders.show', $order)->with('success', $message);
    }
}
