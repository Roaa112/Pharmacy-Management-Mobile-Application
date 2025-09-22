<?php

namespace App\Modules\FlashSale;

use App\Models\FlashSale;
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
        $flashSales = $this->flashSaleService->listAllFlashSales($request->all());
    
        return view('dashboard.FlashSales.index', [
            'flashSales' => $flashSales['data'], 
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

    public function destroy($id)
    {
        $this->flashSaleService->deleteFlashSale($id);
        return redirect()->back()->with('success', 'FlashSale deleted successfully!');
    }
}
