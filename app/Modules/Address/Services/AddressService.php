<?php

namespace App\Modules\Address\Services;

use App\Modules\Address\Resources\AddressCollection;
use App\Modules\Address\Repositories\AddressesRepository;
use App\Modules\Address\Requests\ListAllAddressesRequest;

class AddressService
{
    public function __construct(private AddressesRepository $addresssRepository)
    {
    }

    public function createAddress($request)
    {

        $Address = $this->constructAddressModel($request);
        return $this->addresssRepository->create($Address);
    }

 public function updateAddress($id, $request)
{
    $Address = $this->constructAddressModel($request, true);
    $updatedAddress = $this->addresssRepository->update($id, $Address);

    if (!$updatedAddress) {
        return null;
    }

    return $updatedAddress;
}



    public function deleteAddress($id)
    {
        return $this->addresssRepository->delete($id);
    }

    public function listAllAddresses(array $queryParameters)
    {

        // Construct Query Criteria
       $listAllAddresss = (new ListAllAddressesRequest)->constructQueryCriteria($queryParameters);


        // Get Countries from Database
        $Addresss = $this->addresssRepository->findAllBy($listAllAddresss);

        return [
            'data' => new AddressCollection($Addresss['data']),
            'count' => $Addresss['count']
        ];
    }



    public function getAddressById($id)
    {
        return $this->addresssRepository->find($id);
    }

 public function constructAddressModel($request, $isUpdate = false)
{
    $data = [
        'address'     => $request['address'] ?? null,
        'governorate' => $request['governorate'] ?? null,
        'city'        => $request['city'] ?? null,
        'street'      => $request['street'] ?? null,
        'building'    => $request['building'] ?? null,
        'apartment'   => $request['apartment'] ?? null,
        'landmark'    => $request['landmark'] ?? null,
        'type'        => $request['type'] ?? null,
        'is_default'  => $request['is_default'] ?? false,
    ];

    if (!$isUpdate) {
        $data['user_id'] = $request['user_id'] ?? null;
    }

    return $data;
}



}
