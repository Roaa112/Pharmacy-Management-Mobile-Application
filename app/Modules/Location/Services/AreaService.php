<?php

namespace App\Modules\Location\Services;

use App\Modules\Location\Repositories\AreaRepository;
use App\Modules\Location\Requests\ListAllAreasRequest;
use App\Modules\Location\Requests\ListAllCitiesRequest;

class AreaService
{
    public function __construct(private AreaRepository $areaRepository)
    {
    }

    public function listAllAreas($queryParameters)
    {

        $listAllAreas = (new ListAllAreasRequest)->constructQueryCriteria($queryParameters);

       
        $Areas = $this->areaRepository->getAllAreas($listAllAreas);

        return [
            'data' => $Areas['data'],
            'count' => $Areas['count']
        ];
    }


  
}
