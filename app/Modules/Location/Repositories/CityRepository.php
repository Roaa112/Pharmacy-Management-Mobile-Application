<?php

namespace App\Modules\Location\Repositories;

use App\Models\City;
use App\Modules\Shared\Repositories\BaseRepository;

class CityRepository extends BaseRepository
{
    public function __construct(private City $model)
    {
        parent::__construct($model);
    }

     public function getCity($name){
        $city = $this->model::where('name',$name)->get();
       return $city;
     }
}
