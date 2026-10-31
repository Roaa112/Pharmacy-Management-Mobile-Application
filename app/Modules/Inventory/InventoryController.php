<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\ReturnRequestItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock overview and the stock movement ledger. Stock is changed from the product
 * form (which writes ledger rows), so these pages are read-only.
 */
class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $threshold = (int) config('pharmacy.low_stock_threshold');
        $filter = $request->query('stock', 'all');
        $search = trim((string) $request->query('search'));

        $sizes = ProductSize::query()
            ->whereHas('product', fn ($q) => $q->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%"))))
            ->with(['product' => fn ($q) => $q->basic()->select('id', 'name_ar', 'name_en', 'image', 'category_id'), 'product.category:id,name_en'])
            ->when($filter === 'out', fn ($q) => $q->where('stock', '<=', 0))
            ->when($filter === 'low', fn ($q) => $q->whereBetween('stock', [1, $threshold]))
            ->when($filter === 'ok', fn ($q) => $q->where('stock', '>', $threshold))
            ->orderBy('stock')
            ->orderBy('product_id')
            ->paginate(25)
            ->withQueryString();

        $listed = ProductSize::whereHas('product');

        $summary = [
            'sizes' => (clone $listed)->count(),
            'units' => (int) (clone $listed)->sum('stock'),
            // Returned units held apart (decision D1): never sellable, not part of units or value.
            'quarantined' => (int) (clone $listed)->sum('quarantined_stock'),
            // Stock value at selling price (there is no cost price in the system).
            'value' => (float) (clone $listed)->where('stock', '>', 0)->sum(DB::raw('stock * price')),
            'low' => (clone $listed)->whereBetween('stock', [1, $threshold])->count(),
            'out' => (clone $listed)->where('stock', '<=', 0)->count(),
        ];

        return view('dashboard.inventory.index', compact('sizes', 'summary', 'filter', 'search', 'threshold'));
    }

    public function movements(Request $request)
    {
        $type = $request->query('type');
        $sizeId = $request->integer('size') ?: null;

        $movements = StockMovement::query()
            ->with(['size.product' => fn ($q) => $q->basic()->withTrashed()->select('id', 'name_en', 'name_ar')])
            ->when(in_array($type, ['initial', 'sale', 'cancellation', 'adjustment', 'return_quarantine'], true), fn ($q) => $q->where('type', $type))
            ->when($sizeId, fn ($q) => $q->where('product_size_id', $sizeId))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $size = $sizeId ? ProductSize::with(['product' => fn ($q) => $q->basic()->withTrashed()])->find($sizeId) : null;

        // Order numbers for sale/cancellation rows, in one query.
        $orderIds = OrderItem::whereIn('id', $movements->where('reference_type', (new OrderItem)->getMorphClass())->pluck('reference_id'))
            ->pluck('order_id', 'id');

        // Return numbers for quarantine rows, in one query.
        $returnIds = ReturnRequestItem::whereIn('id', $movements->where('reference_type', (new ReturnRequestItem)->getMorphClass())->pluck('reference_id'))
            ->pluck('return_request_id', 'id');

        return view('dashboard.inventory.movements', compact('movements', 'type', 'size', 'orderIds', 'returnIds'));
    }
}
