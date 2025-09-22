<?php

namespace App\Modules\DeliveryPrice;

use App\Models\DeliveryPrice;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\DeliveryPrice\Services\DeliveryPriceService;
use App\Modules\DeliveryPrice\Requests\StoreDeliveryPriceRequest;
use App\Modules\DeliveryPrice\Requests\UpdateDeliveryPriceRequest;

class DeliveryPriceController extends Controller
{
    public function __construct(private DeliveryPriceService $deliveryPriceService)
    {
    }
public function indexapi(Request $request)
{
    $deliveryPrices = $this->deliveryPriceService->listAllDeliveryPrices($request->all());

    return response()->json([
        'status' => true,
        'message' => 'Delivery prices fetched successfully.',
        'data' => $deliveryPrices['data'],
    ]);
}

    public function index(Request $request)
    {
        $deliveryPrice = $this->deliveryPriceService->listAllDeliveryPrices($request->all());

        return view('dashboard.DeliveryPrice.index', [
            'deliveryPrice' => $deliveryPrice['data'],
        ]);
    }


    public function store(StoreDeliveryPriceRequest $request)
    {

        $this->deliveryPriceService->createDeliveryPrice($request->validated());
        return redirect()->back()->with('success', 'DeliveryPrice created successfully!');
    }

    public function update(UpdateDeliveryPriceRequest $request, $id)
    {

        $this->deliveryPriceService->updateDeliveryPrice($id, $request->validated());
        return redirect()->back()->with('success', 'DeliveryPrice updated successfully!');
    }

    public function destroy($id)
    {
        $this->deliveryPriceService->deleteDeliveryPrice($id);
        return redirect()->back()->with('success', 'DeliveryPrice deleted successfully!');
    }
}
