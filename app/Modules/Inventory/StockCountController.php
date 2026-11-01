<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\InventoryBalance;
use App\Models\ProductSize;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\StockCountService;
use DomainException;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts)
    {
    }

    public function index()
    {
        return view('dashboard.counts.index', [
            'counts' => StockCount::with('warehouse:id,code,name')->withCount('items')->latest('id')->paginate(25),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id', 'notes' => 'nullable|string|max:1000']);
        $count = $this->counts->start($data['warehouse_id'], $data['notes'] ?? null, auth('admin')->id());
        AdminAuditLog::record('count.start', $count);

        return redirect()->route('dashboard.counts.show', $count)->with('success', 'Count started. Enter the counted quantities.');
    }

    public function show(StockCount $count)
    {
        $count->load(['warehouse', 'items']);

        // Every SKU known in this warehouse, plus any already counted; system figures shown for drafts.
        $sizeIds = InventoryBalance::where('warehouse_id', $count->warehouse_id)->pluck('product_size_id')->merge($count->items->pluck('product_size_id'))->unique();
        $sizes = ProductSize::whereIn('id', $sizeIds)->with(['product' => fn ($q) => $q->basic()->withTrashed()->select('id', 'name_en')])->orderBy('product_id')->get();
        $balances = InventoryBalance::where('warehouse_id', $count->warehouse_id)->get()->keyBy('product_size_id');

        return view('dashboard.counts.show', ['count' => $count, 'sizes' => $sizes, 'balances' => $balances, 'items' => $count->items->keyBy('product_size_id')]);
    }

    public function record(Request $request, StockCount $count)
    {
        $data = $request->validate(['counts' => 'required|array', 'counts.*' => 'nullable|integer|min:0']);

        try {
            $this->counts->record($count, $data['counts']);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Counted quantities saved.');
    }

    public function complete(StockCount $count)
    {
        try {
            $count = $this->counts->complete($count, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('count.complete', $count, ['differences' => $count->items->where('difference', '!=', 0)->pluck('difference', 'product_size_id')]);

        return redirect()->back()->with('success', 'Count completed; differences recorded as corrections.');
    }

    public function cancel(StockCount $count)
    {
        try {
            $this->counts->cancel($count);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Count canceled.');
    }
}
