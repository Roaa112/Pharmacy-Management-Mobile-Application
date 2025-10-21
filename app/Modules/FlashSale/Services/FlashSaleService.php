<?php

namespace App\Modules\FlashSale\Services;

use App\Models\FlashSale;

use App\Modules\FlashSale\Resources\FlashSaleCollection;
use App\Modules\FlashSale\Repositories\FlashSalesRepository;
use App\Modules\FlashSale\Requests\ListAllFlashSalesRequest;

class FlashSaleService
{
    public function __construct(private FlashSalesRepository $flashSalesRepository)
    {
    }
  
    public function createFlashSale($request)
    {
        $flashSale = $this->constructFlashSaleModel($request);
        return $this->flashSalesRepository->create($flashSale);
    }

    public function updateFlashSale($id, $request)
    {
       
        $flashSale = $this->constructFlashSaleModel($request); 
      
        return $this->flashSalesRepository->update($id, $flashSale);
    }

    public function deleteFlashSale($id)
    {
        return $this->flashSalesRepository->delete($id);
    }

    public function listAllFlashSales(array $queryParameters)
    {
      
        $listAllFlashSales= (new ListAllFlashSalesRequest)->constructQueryCriteria($queryParameters);
        $flashSales= $this->flashSalesRepository->findAllBy($listAllFlashSales );

        return [
            'data' => new FlashSaleCollection($flashSales['data']),
            'count' => $flashSales['count']
        ];
    }

    public function getFlashSaleById($id)
    {
        return $this->flashSalesRepository->find($id);
    }

    public function constructFlashSaleModel($request)
{
    $flashSaleModel = [
        'discount_value' => $request['discount_value'],
        'time' => $request['time'],
        'is_active' => $request['is_active'],
        'date' => $request['date'],
       
    ];

    return $flashSaleModel;
}

    
   
  
}
