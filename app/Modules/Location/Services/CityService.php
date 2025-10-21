<?php

namespace App\Modules\Location\Services;

use App\Modules\Location\Repositories\CityRepository;
use App\Modules\Location\Requests\ListAllCitiesRequest;

class CityService
{
    public function __construct(private CityRepository $cityRepository)
    {
    }

    public function listAllCities($queryParameters)
    {
        // Construct Query Criteria
        $listAllCities = (new ListAllCitiesRequest)->constructQueryCriteria($queryParameters);
        
        // Get Countries from Database
        $cities = $this->cityRepository->findAllBy($listAllCities);
         
        return [
            'data' => $cities['data'],
            'count' => $cities['count']
        ];
    }


    public function getCityByName($name){
        return $this->cityRepository->getCity($name);
    }

}
