<?php

namespace App\Modules\DeliveryPrice\Services;

use App\Models\DeliveryPrice;

use App\Modules\DeliveryPrice\Resources\DeliveryPriceCollection;
use App\Modules\DeliveryPrice\Repositories\DeliveryPricesRepository;
use App\Modules\DeliveryPrice\Requests\ListAllDeliveryPricesRequest;

class DeliveryPriceService
{
    public function __construct(private DeliveryPricesRepository $deliveryPricesRepository)
    {
    }

    public function createDeliveryPrice($request)
    {
        $deliveryPrice = $this->constructDeliveryPriceModel($request);
        return $this->deliveryPricesRepository->create($deliveryPrice);
    }

    public function updateDeliveryPrice($id, $request)
    {

        $deliveryPrice = $this->constructDeliveryPriceModel($request);

        return $this->deliveryPricesRepository->update($id, $deliveryPrice);
    }

    public function deleteDeliveryPrice($id)
    {
        return $this->deliveryPricesRepository->delete($id);
    }

    public function listAllDeliveryPrices(array $queryParameters)
    {

        $listAllDeliveryPrices= (new ListAllDeliveryPricesRequest)->constructQueryCriteria($queryParameters);
        $deliveryPrices= $this->deliveryPricesRepository->findAllBy($listAllDeliveryPrices );

        return [
            'data' => new DeliveryPriceCollection($deliveryPrices['data']),
            'count' => $deliveryPrices['count']
        ];
    }

    public function getDeliveryPriceById($id)
    {
        return $this->deliveryPricesRepository->find($id);
    }

    public function constructDeliveryPriceModel($request)
{
    $deliveryPriceModel = [
        'governorate' => $request['governorate'],
        'price' => $request['price'],
       

    ];

    return $deliveryPriceModel;
}




}
