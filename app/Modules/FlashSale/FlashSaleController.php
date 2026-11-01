<?php

namespace App\Modules\FlashSale;

use App\Models\AdminAuditLog;
use App\Models\FlashSale;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\FlashSale\Services\FlashSaleService;
use App\Modules\FlashSale\Requests\StoreFlashSaleRequest;
use App\Modules\FlashSale\Requests\UpdateFlashSaleRequest;

class FlashSaleController extends Controller
{
    public function __construct(private FlashSaleService $flashSaleService)
    {
    }

    public function index(Request $request)
    {
        $flashSales = FlashSale::with(['items.product' => fn ($q) => $q->basic(), 'items.size'])->orderByDesc('date')->orderByDesc('time')->get();

        // Units sold at the flash price (orders not canceled), per sale.
        $sold = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.flash_sale_id', $flashSales->pluck('id'))->where('orders.status', '!=', 'canceled')->where('order_items.is_gift', false)
            ->groupBy('order_items.flash_sale_id')->selectRaw('order_items.flash_sale_id, sum(order_items.quantity) as units, sum(order_items.discount_amount) as discount')
            ->get()->keyBy('flash_sale_id');

        return view('dashboard.FlashSales.index', [
            'flashSales' => $flashSales,
            'sold' => $sold,
            'products' => Product::basic()->orderBy('name_en')->pluck('name_en', 'id'),
            'sizes' => ProductSize::with(['product' => fn ($q) => $q->basic()])->whereHas('product')->orderBy('product_id')->orderBy('id')->get(),
        ]);
    }

    public function store(StoreFlashSaleRequest $request)
    {
        $this->flashSaleService->createFlashSale($request->validated());
        return redirect()->back()->with('success', 'FlashSale created successfully!');
    }

    public function update(UpdateFlashSaleRequest $request, $id)
    {
        $this->flashSaleService->updateFlashSale($id, $request->validated());
        return redirect()->back()->with('success', 'FlashSale updated successfully!');
    }

    public function toggle($id)
    {
        $sale = FlashSale::findOrFail($id);
        $sale->update(['is_active' => !$sale->is_active]);
        AdminAuditLog::record($sale->is_active ? 'flash_sale.activated' : 'flash_sale.deactivated', $sale);

        return back()->with('success', $sale->is_active ? 'Flash sale activated.' : 'Flash sale deactivated.');
    }

    public function destroy($id)
    {
        $this->flashSaleService->deleteFlashSale($id);
        return redirect()->back()->with('success', 'FlashSale deleted successfully!');
    }
}
