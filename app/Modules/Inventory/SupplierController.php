<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ProductSize;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\PurchasingService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Suppliers, their account (invoices, payments, return credits) and supplier returns. */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $suppliers = Supplier::withCount(['purchaseOrders as open_orders_count' => fn ($q) => $q->whereIn('status', ['ordered', 'partially_received'])])
            ->withSum('invoices', 'amount')->withSum('payments', 'amount')
            ->withSum(['returns as returns_credit' => fn ($q) => $q->where('status', SupplierReturn::DISPATCHED)], 'credit_amount')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', $search)->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('dashboard.suppliers.index', compact('suppliers', 'search'));
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($this->validated($request));
        AdminAuditLog::record('supplier.create', $supplier, $supplier->toArray());

        return redirect()->route('dashboard.suppliers.show', $supplier)->with('success', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validated($request, $supplier));
        AdminAuditLog::record('supplier.update', $supplier, $supplier->getChanges());

        return redirect()->back()->with('success', 'Supplier saved.');
    }

    public function show(Supplier $supplier)
    {
        $supplier->load([
            'purchaseOrders' => fn ($q) => $q->latest('id')->limit(50),
            'invoices' => fn ($q) => $q->withSum('payments', 'amount')->latest('invoice_date'),
            'payments' => fn ($q) => $q->latest('paid_on'),
            'returns' => fn ($q) => $q->with('items.size.product:id,name_en')->latest('id'),
        ]);

        return view('dashboard.suppliers.show', [
            'supplier' => $supplier,
            'balance' => Money::fromMinor($supplier->balanceMinor()),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'sizes' => ProductSize::whereHas('product')->with(['product' => fn ($q) => $q->basic()->select('id', 'name_en')])->orderBy('product_id')->get(['id', 'product_id', 'size', 'sku', 'cost_price']),
        ]);
    }

    public function storeInvoice(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64', Rule::unique('supplier_invoices', 'number')->where('supplier_id', $supplier->id)],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('supplier_id', $supplier->id)],
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
        ]);

        $invoice = $supplier->invoices()->create($data + ['currency_code' => $supplier->currency_code, 'created_by' => auth('admin')->id()]);
        AdminAuditLog::record('supplier.invoice', $invoice, $invoice->toArray());

        return redirect()->back()->with('success', 'Invoice recorded.');
    }

    public function storePayment(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'supplier_invoice_id' => ['nullable', 'integer', Rule::exists('supplier_invoices', 'id')->where('supplier_id', $supplier->id)],
            'amount' => 'required|numeric|min:0.01',
            'paid_on' => 'required|date|before_or_equal:today',
            'method' => 'nullable|string|max:30',
            'reference' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($data['supplier_invoice_id'] ?? null) {
            $invoice = SupplierInvoice::withSum('payments', 'amount')->find($data['supplier_invoice_id']);
            if (Money::toMinor($invoice->payments_sum_amount) + Money::toMinor($data['amount']) > Money::toMinor($invoice->amount)) {
                return redirect()->back()->withInput()->with('error', 'The payment is larger than what is left on that invoice.');
            }
        }

        $payment = $supplier->payments()->create($data + ['created_by' => auth('admin')->id()]);
        AdminAuditLog::record('supplier.payment', $payment, $payment->toArray());

        return redirect()->back()->with('success', 'Payment recorded.');
    }

    public function storeReturn(Request $request, Supplier $supplier, PurchasingService $purchasing)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'reason' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.product_size_id' => 'required|integer|exists:product_sizes,id',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.source_bucket' => 'required|in:on_hand,damaged,quarantined',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        try {
            $return = $purchasing->createReturn($supplier, $data['warehouse_id'], $data['lines'], $data['reason'] ?? null, null, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('supplier.return', $return, ['credit' => $return->credit_amount]);

        return redirect()->back()->with('success', "Supplier return {$return->reference} drafted. Dispatch it when the goods leave.");
    }

    public function dispatchReturn(SupplierReturn $return, PurchasingService $purchasing)
    {
        try {
            $purchasing->dispatchReturn($return, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('supplier.return_dispatch', $return);

        return redirect()->back()->with('success', 'Goods returned to the supplier; stock and balance updated.');
    }

    public function cancelReturn(SupplierReturn $return, PurchasingService $purchasing)
    {
        try {
            $purchasing->cancelReturn($return);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Supplier return canceled.');
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'currency_code' => 'nullable|string|size:3',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
