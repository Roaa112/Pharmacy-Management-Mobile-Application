<?php

namespace App\Modules\Location;

use App\Http\Controllers\Controller;
use App\Modules\Location\Services\AreaService;
use App\Modules\Location\Services\CityService;
use App\Modules\Shared\Enums\HttpStatusCodeEnum;
use App\Modules\Location\Requests\ListAreasRequest;
use App\Modules\Location\Requests\ListCitiesRequest;

class LocationController extends Controller
{
    public function __construct(private CityService $cityService,private AreaService $areaService)
    {
    }
    
    public function listAllCites(ListCitiesRequest $request)
    {

        $cities = $this->cityService->listAllCities( $request->validated());
    
        return successJsonResponse(data_get($cities, 'data'), __('cities.success.get_all_cities'), data_get($cities, 'count'));
    }

    public function listAllAreas(ListAreasRequest $request)
    {  
        $areas = $this->areaService->listAllAreas( $request->validated());
        return successJsonResponse(data_get($areas, 'data'), __('Areas.success.get_all_Areas'), data_get(     $areas , 'count'));
    }
    

   
    
}