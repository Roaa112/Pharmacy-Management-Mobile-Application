<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\BatchBalance;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\ProductSize;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Inventory\Services\InventoryService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Inventory dashboard: overview, stock per SKU and warehouse, the movement ledger, each SKU's
 * full history, manual operations (adjust, damage, write-off, quarantine inspection) and reports.
 * Every write goes through InventoryService and is audited.
 */
class InventoryController extends Controller
{
    private const STOCK_FILTERS = ['all', 'low', 'out', 'ok', 'overstock', 'reserved', 'damaged', 'quarantined'];

    public function index(Request $request)
    {
        $threshold = Setting::current()->lowStockThreshold();
        $filter = in_array($request->query('stock'), self::STOCK_FILTERS, true) ? $request->query('stock') : 'all';
        $warehouseId = $request->integer('warehouse') ?: null;

        $sizes = $this->sizeQuery($request, $warehouseId)
            ->with(['product' => fn ($q) => $q->basic()->select('id', 'name_ar', 'name_en', 'image', 'category_id'), 'product.category:id,name_en'])
            ->withSum(['balances as on_hand_total' => fn ($q) => $this->forWarehouse($q, $warehouseId)], 'on_hand')
            ->withSum(['balances as reserved_total' => fn ($q) => $this->forWarehouse($q, $warehouseId)], 'reserved')
            ->withSum(['balances as damaged_total' => fn ($q) => $this->forWarehouse($q, $warehouseId)], 'damaged')
            ->withSum(['balances as quarantined_total' => fn ($q) => $this->forWarehouse($q, $warehouseId)], 'quarantined')
            ->withSum(['balances as in_transit_total' => fn ($q) => $this->forWarehouse($q, $warehouseId)], 'in_transit')
            ->when($filter === 'out', fn ($q) => $q->where('stock', '<=', 0))
            ->when($filter === 'low', fn ($q) => $q->where('stock', '>', 0)->whereRaw('stock <= COALESCE(reorder_point, ?)', [$threshold]))
            ->when($filter === 'ok', fn ($q) => $q->whereRaw('stock > COALESCE(reorder_point, ?)', [$threshold]))
            ->when($filter === 'overstock', fn ($q) => $q->whereNotNull('max_stock')->whereColumn('stock', '>', 'max_stock'))
            ->when(in_array($filter, ['reserved', 'damaged', 'quarantined'], true), fn ($q) => $q->whereHas('balances', fn ($b) => $this->forWarehouse($b, $warehouseId)->where($filter, '>', 0)))
            ->orderBy('stock')->orderBy('product_id')
            ->paginate(25)->withQueryString();

        return view('dashboard.inventory.index', [
            'sizes' => $sizes,
            'summary' => $this->summary($threshold),
            'warehouseSummary' => $this->warehouseSummary(),
            'filter' => $filter,
            'threshold' => $threshold,
            'warehouses' => Warehouse::orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::orderBy('name_en')->get(['id', 'name_en']),
            'colors' => ProductSize::whereNotNull('color')->distinct()->orderBy('color')->pluck('color'),
        ]);
    }

    public function movements(Request $request)
    {
        $type = in_array($request->query('type'), StockMovement::TYPES, true) ? $request->query('type') : null;
        $sizeId = $request->integer('size') ?: null;
        $search = trim((string) $request->query('search'));

        $movements = StockMovement::query()
            ->with(['size.product' => fn ($q) => $q->basic()->withTrashed()->select('id', 'name_en', 'name_ar'), 'warehouse:id,code', 'admin:id,name'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->query('bucket'), fn ($q, $b) => $q->where('bucket', $b))
            ->when($sizeId, fn ($q) => $q->where('product_size_id', $sizeId))
            ->when($request->integer('warehouse'), fn ($q, $w) => $q->where('warehouse_id', $w))
            ->when($request->date('from'), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->when($search !== '', fn ($q) => $q->whereHas('size', fn ($s) => $s->where('sku', $search)->orWhere('barcode', $search)
                ->orWhereHas('product', fn ($p) => $p->withTrashed()->where('name_en', 'like', "%{$search}%")->orWhere('name_ar', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(40)->withQueryString();

        return view('dashboard.inventory.movements', [
            'movements' => $movements,
            'type' => $type,
            'size' => $sizeId ? ProductSize::with(['product' => fn ($q) => $q->basic()->withTrashed()])->find($sizeId) : null,
            'types' => StockMovement::TYPES,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /** Everything that ever happened to one SKU, and how its balances add up. */
    public function show(ProductSize $size)
    {
        $size->load(['product' => fn ($q) => $q->basic()->withTrashed(), 'balances.warehouse']);

        // Totals per movement type and bucket (warehouse ledger only).
        $totals = StockMovement::where('product_size_id', $size->id)->whereNotNull('warehouse_id')
            ->groupBy('type', 'bucket')->selectRaw('type, bucket, SUM(quantity_change) as total, COUNT(*) as count')->get();
        $sum = fn (string $type, string $bucket = 'on_hand') => (int) $totals->where('type', $type)->where('bucket', $bucket)->sum('total');

        $lifecycle = [
            'Opening / initial stock' => $sum(StockMovement::TYPE_OPENING_BALANCE) + $sum(StockMovement::TYPE_INITIAL),
            'Received from suppliers' => $sum(StockMovement::TYPE_PURCHASE_RECEIPT),
            'Received damaged' => $sum(StockMovement::TYPE_PURCHASE_RECEIPT, 'damaged'),
            'Sold (dispatched)' => -$sum(StockMovement::TYPE_FULFILLMENT),
            'Given as gifts' => -$sum(StockMovement::TYPE_GIFT),
            'Reserved for orders (total)' => (int) $totals->where('type', StockMovement::TYPE_RESERVATION)->sum('total'),
            'Reservations released' => -(int) $totals->where('type', StockMovement::TYPE_RESERVATION_RELEASE)->sum('total'),
            'Returned by customers (to quarantine)' => (int) $totals->whereIn('type', [StockMovement::TYPE_RETURN_QUARANTINE, StockMovement::TYPE_SHIPMENT_RETURN, StockMovement::TYPE_CANCELLATION_RETURN])->sum('total'),
            'Returns put back on sale after inspection' => $sum(StockMovement::TYPE_RETURN_RESTOCK),
            'Marked damaged' => $sum(StockMovement::TYPE_DAMAGE, 'damaged') + $sum(StockMovement::TYPE_RETURN_DAMAGED, 'damaged'),
            'Written off' => -((int) $totals->where('type', StockMovement::TYPE_WRITE_OFF)->sum('total')),
            'Transferred in' => $sum(StockMovement::TYPE_TRANSFER_IN),
            'Transferred out' => -$sum(StockMovement::TYPE_TRANSFER_OUT),
            'Lost in transfer' => -$sum(StockMovement::TYPE_TRANSFER_LOSS, 'in_transit'),
            'Returned to suppliers' => -((int) $totals->where('type', StockMovement::TYPE_SUPPLIER_RETURN)->sum('total')),
            'Manual adjustments (net)' => $sum(StockMovement::TYPE_ADJUSTMENT),
            'Count corrections (net)' => $sum(StockMovement::TYPE_COUNT_CORRECTION),
            'Cancellations put back (legacy orders)' => $sum(StockMovement::TYPE_CANCELLATION),
            'Expired (taken off sale)' => -$sum(StockMovement::TYPE_EXPIRY),
        ];

        $current = [
            'on_hand' => (int) $size->balances->sum('on_hand'),
            'reserved' => (int) $size->balances->sum('reserved'),
            'available' => (int) $size->balances->sum(fn ($b) => $b->available),
            'damaged' => (int) $size->balances->sum('damaged'),
            'quarantined' => (int) $size->balances->sum('quarantined'),
            'in_transit' => (int) $size->balances->sum('in_transit'),
        ];
        $current['value_at_cost'] = $size->cost_price !== null ? $current['on_hand'] * (float) $size->cost_price : null;

        // The on-hand balance is exactly the sum of its ledger rows.
        $ledgerOnHand = (int) $totals->where('bucket', 'on_hand')->sum('total');

        $timeline = StockMovement::where('product_size_id', $size->id)->with(['warehouse:id,code', 'admin:id,name', 'user:id,name'])
            ->latest('id')->paginate(50);

        return view('dashboard.inventory.show', [
            'size' => $size,
            'lifecycle' => $lifecycle,
            'current' => $current,
            'ledgerOnHand' => $ledgerOnHand,
            'timeline' => $timeline,
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'reservations' => $size->reservations()->where('status', 'active')->with('orderItem:id,order_id', 'warehouse:id,code', 'batch')->get(),
            'batches' => BatchBalance::where('product_size_id', $size->id)->with('batch', 'warehouse:id,code')
                ->whereRaw('on_hand + damaged + quarantined + in_transit > 0')->get()
                ->sortBy(fn ($b) => [$b->batch->expiry_date === null ? 1 : 0, $b->batch->expiry_date?->toDateString(), $b->inventory_batch_id])->values(),
            'batchOptions' => InventoryBatch::where('product_size_id', $size->id)->fefo()->get(),
        ]);
    }

    /** SKU master data: code, barcode, colour, cost and replenishment levels. */
    public function updateSku(Request $request, ProductSize $size)
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('product_sizes', 'sku')->ignore($size->id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('product_sizes', 'barcode')->ignore($size->id)],
            'color' => 'nullable|string|max:50',
            'cost_price' => 'nullable|numeric|min:0|max:99999999',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0|gte:min_stock',
            'reorder_point' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:1',
        ]);

        $size->update($data);
        AdminAuditLog::record('inventory.sku', $size, $size->getChanges());

        return redirect()->back()->with('success', 'SKU details saved.');
    }

    /** Manual operations: adjust (signed), damage, write-off, inspect quarantine. Reason mandatory. */
    public function operate(Request $request, ProductSize $size, InventoryService $inventory)
    {
        $data = $request->validate([
            'operation' => 'required|in:adjust,damage,write_off,inspect',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required_unless:operation,inspect|nullable|integer|not_in:0|between:-1000000,1000000',
            'restock' => 'nullable|integer|min:0',
            'damaged' => 'nullable|integer|min:0',
            'disposed' => 'nullable|integer|min:0',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'batch_id' => ['nullable', 'integer', Rule::exists('inventory_batches', 'id')->where('product_size_id', $size->id)],
        ]);
        $adminId = auth('admin')->id();
        $batchId = isset($data['batch_id']) ? (int) $data['batch_id'] : null;

        try {
            match ($data['operation']) {
                'adjust' => $inventory->adjust($size->id, $data['warehouse_id'], (int) $data['quantity'], $data['reason'], $adminId, $data['notes'] ?? null, $batchId),
                'damage' => $inventory->markDamaged($size->id, $data['warehouse_id'], (int) $data['quantity'], $data['reason'], $adminId, $batchId),
                'write_off' => $inventory->writeOffDamaged($size->id, $data['warehouse_id'], (int) $data['quantity'], $data['reason'], $adminId, $batchId),
                'inspect' => $inventory->inspectQuarantine($size->id, $data['warehouse_id'], (int) ($data['restock'] ?? 0), (int) ($data['damaged'] ?? 0), (int) ($data['disposed'] ?? 0), $data['reason'], $adminId, $batchId),
            };
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('inventory.'.$data['operation'], $size, $data);

        return redirect()->back()->with('success', 'Stock updated.');
    }

    public function reports(Request $request, InventoryIntegrity $integrity)
    {
        $threshold = Setting::current()->lowStockThreshold();
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        $valuationByWarehouse = DB::table('inventory_balances')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->join('product_sizes', 'product_sizes.id', '=', 'inventory_balances.product_size_id')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->selectRaw('warehouses.name, SUM(inventory_balances.on_hand) as units,
                SUM(inventory_balances.on_hand * COALESCE(product_sizes.cost_price, 0)) as cost_value,
                SUM(inventory_balances.on_hand * product_sizes.price) as retail_value,
                SUM(CASE WHEN product_sizes.cost_price IS NULL AND inventory_balances.on_hand > 0 THEN 1 ELSE 0 END) as without_cost')
            ->get();

        $valuationByCategory = DB::table('inventory_balances')
            ->join('product_sizes', 'product_sizes.id', '=', 'inventory_balances.product_size_id')
            ->join('products', 'products.id', '=', 'product_sizes.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.name_en')
            ->selectRaw('COALESCE(categories.name_en, ?) as category, SUM(inventory_balances.on_hand) as units,
                SUM(inventory_balances.on_hand * COALESCE(product_sizes.cost_price, 0)) as cost_value', ['Uncategorized'])
            ->orderByDesc('cost_value')->get();

        $reorder = ProductSize::query()->whereHas('product')
            ->with(['product' => fn ($q) => $q->basic()->select('id', 'name_en')])
            ->whereRaw('stock <= COALESCE(reorder_point, ?)', [$threshold])
            ->orderBy('stock')->limit(100)->get();

        $movementSummary = StockMovement::whereNotNull('warehouse_id')->whereBetween('created_at', [$from, $to])
            ->groupBy('type', 'bucket')->selectRaw('type, bucket, COUNT(*) as count, SUM(quantity_change) as units')
            ->orderBy('type')->get();

        return view('dashboard.inventory.reports', [
            'valuationByWarehouse' => $valuationByWarehouse,
            'valuationByCategory' => $valuationByCategory,
            'reorder' => $reorder,
            'threshold' => $threshold,
            'movementSummary' => $movementSummary,
            'from' => $from,
            'to' => $to,
            'problems' => $integrity->problems(),
            'nearExpiryDays' => $days = max(0, (int) config('pharmacy.near_expiry_days')),
            'expiring' => BatchBalance::query()
                ->whereHas('batch', fn ($q) => $q->whereNotNull('expiry_date')->where('expiry_date', '<=', \App\Support\StoreClock::now()->addDays($days)->toDateString()))
                ->whereRaw('on_hand + damaged + quarantined + in_transit > 0')->with(['batch.size.product:id,name_en', 'warehouse:id,code'])->limit(200)->get()
                ->sortBy(fn ($b) => $b->batch->expiry_date->toDateString())->values(),
            'unknownExpiryUnits' => (int) BatchBalance::whereHas('batch', fn ($q) => $q->whereNull('expiry_date'))->sum('on_hand'),
        ]);
    }

    private function sizeQuery(Request $request, ?int $warehouseId): Builder
    {
        $search = trim((string) $request->query('search'));

        return ProductSize::query()
            ->whereHas('product', fn ($q) => $q->when($request->integer('category'), fn ($q, $c) => $q->where('category_id', $c)))
            // Name, or an exact SKU / barcode (scanner input).
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('sku', $search)->orWhere('barcode', $search)
                ->orWhereHas('product', fn ($p) => $p->where('name_en', 'like', "%{$search}%")->orWhere('name_ar', 'like', "%{$search}%"))))
            ->when($request->query('size_label'), fn ($q, $l) => $q->where('size', $l))
            ->when($request->query('color'), fn ($q, $c) => $q->where('color', $c))
            ->when($warehouseId, fn ($q) => $q->whereHas('balances', fn ($b) => $b->where('warehouse_id', $warehouseId)));
    }

    private function forWarehouse($query, ?int $warehouseId)
    {
        return $query->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));
    }

    private function summary(int $threshold): array
    {
        $sizes = ProductSize::whereHas('product');
        $balances = InventoryBalance::query()->whereIn('product_size_id', (clone $sizes)->select('id'));
        $totals = (clone $balances)->selectRaw('COALESCE(SUM(on_hand),0) as on_hand, COALESCE(SUM(reserved),0) as reserved, COALESCE(SUM(damaged),0) as damaged, COALESCE(SUM(quarantined),0) as quarantined, COALESCE(SUM(in_transit),0) as in_transit')->first();

        $value = DB::table('inventory_balances')->join('product_sizes', 'product_sizes.id', '=', 'inventory_balances.product_size_id')
            ->selectRaw('COALESCE(SUM(inventory_balances.on_hand * COALESCE(product_sizes.cost_price, 0)),0) as cost, COALESCE(SUM(inventory_balances.on_hand * product_sizes.price),0) as retail')->first();

        return [
            'products' => \App\Models\Product::count(),
            'skus' => (clone $sizes)->count(),
            'on_hand' => (int) $totals->on_hand,
            'available' => (int) (clone $sizes)->sum('stock'),
            'reserved' => (int) $totals->reserved,
            'damaged' => (int) $totals->damaged,
            'quarantined' => (int) $totals->quarantined,
            'in_transit' => (int) $totals->in_transit,
            'low' => (clone $sizes)->where('stock', '>', 0)->whereRaw('stock <= COALESCE(reorder_point, ?)', [$threshold])->count(),
            'out' => (clone $sizes)->where('stock', '<=', 0)->count(),
            'overstock' => (clone $sizes)->whereNotNull('max_stock')->whereColumn('stock', '>', 'max_stock')->count(),
            'value_cost' => (float) $value->cost,
            'value_retail' => (float) $value->retail,
            'without_cost' => (clone $sizes)->whereNull('cost_price')->where('stock', '>', 0)->count(),
        ];
    }

    private function warehouseSummary()
    {
        return Warehouse::query()
            ->leftJoin('inventory_balances', 'inventory_balances.warehouse_id', '=', 'warehouses.id')
            ->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name', 'warehouses.is_sellable', 'warehouses.is_active', 'warehouses.is_default')
            ->select('warehouses.id', 'warehouses.code', 'warehouses.name', 'warehouses.is_sellable', 'warehouses.is_active', 'warehouses.is_default')
            ->selectRaw('COALESCE(SUM(inventory_balances.on_hand),0) as on_hand, COALESCE(SUM(inventory_balances.reserved),0) as reserved,
                COALESCE(SUM(inventory_balances.damaged),0) as damaged, COALESCE(SUM(inventory_balances.quarantined),0) as quarantined,
                COALESCE(SUM(inventory_balances.in_transit),0) as in_transit, COUNT(inventory_balances.id) as skus')
            ->orderByDesc('warehouses.is_default')->orderBy('warehouses.name')
            ->get();
    }
}
