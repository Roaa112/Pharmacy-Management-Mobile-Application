<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Country;
use App\Models\InventoryBalance;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Warehouses and their bin locations. A warehouse holding stock cannot be deactivated. */
class WarehouseController extends Controller
{
    public function index()
    {
        return view('dashboard.warehouses.index', [
            'warehouses' => Warehouse::with(['locations'])->withCount('balances')
                ->withSum('balances', 'on_hand')->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $warehouse = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            if ($data['is_default']) {
                Warehouse::query()->update(['is_default' => false]);
            }

            return Warehouse::create($data);
        });

        $this->refreshProjections($inventory);
        AdminAuditLog::record('warehouse.create', $warehouse, $warehouse->toArray());

        return redirect()->back()->with('success', 'Warehouse created.');
    }

    public function update(Request $request, Warehouse $warehouse, InventoryService $inventory)
    {
        $data = $this->validated($request, $warehouse);

        $holdsStock = InventoryBalance::where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('on_hand', '>', 0)->orWhere('reserved', '>', 0)->orWhere('in_transit', '>', 0)->orWhere('damaged', '>', 0)->orWhere('quarantined', '>', 0))
            ->exists();

        if (!$data['is_active'] && $holdsStock) {
            return redirect()->back()->with('error', 'Move or write off the stock of this warehouse before deactivating it.');
        }
        if (!$data['is_default'] && $warehouse->is_default) {
            return redirect()->back()->with('error', 'Choose another default warehouse first.');
        }

        DB::transaction(function () use ($warehouse, $data) {
            if ($data['is_default']) {
                Warehouse::whereKeyNot($warehouse->id)->update(['is_default' => false]);
            }
            $warehouse->update($data);
        });

        // Changing "sellable" changes what the app can sell.
        if ($warehouse->wasChanged(['is_sellable', 'is_active'])) {
            $this->refreshProjections($inventory, $warehouse->id);
        }

        AdminAuditLog::record('warehouse.update', $warehouse, $warehouse->getChanges());

        return redirect()->back()->with('success', 'Warehouse saved.');
    }

    public function storeLocation(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id)],
            'name' => 'nullable|string|max:255',
        ]);

        $warehouse->locations()->create($data + ['is_active' => true]);

        return redirect()->back()->with('success', 'Location added.');
    }

    public function updateLocation(Request $request, WarehouseLocation $location)
    {
        $location->update($request->validate(['name' => 'nullable|string|max:255']) + ['is_active' => $request->boolean('is_active')]);

        return redirect()->back()->with('success', 'Location saved.');
    }

    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        return $data + [
            'country_id' => \App\Models\Country::default()?->id,
            'is_sellable' => $request->boolean('is_sellable'),
            'is_active' => $request->boolean('is_active'),
            'is_default' => $request->boolean('is_default'),
        ];
    }

    private function refreshProjections(InventoryService $inventory, ?int $warehouseId = null): void
    {
        \App\Models\ProductSize::query()
            ->when($warehouseId, fn ($q) => $q->whereIn('id', InventoryBalance::where('warehouse_id', $warehouseId)->select('product_size_id')))
            ->each(fn ($size) => DB::transaction(fn () => $inventory->refreshProjection(\App\Models\ProductSize::lockForUpdate()->find($size->id))));
    }
}
