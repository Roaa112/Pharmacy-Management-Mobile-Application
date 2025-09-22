<?php

namespace App\Modules\Location\Repositories;

use App\Models\Area;
use App\Modules\Shared\Repositories\BaseRepository;

class AreaRepository extends BaseRepository
{
    public function __construct(private Area $model)
    {
        parent::__construct($model);
    }

    public function getAreasByCityId($city_id){
        $Areas = $this->model::where('city_id',$city_id)->get('name');
        return $Areas;
    }
    public function getAreasByCityName($city_Name){
        $Areas = $this->model->whereHas('city', function ($q) use ($city_Name) {
            $q->where('name', $city_Name);
        });
        return $Areas->get();
    }

    public function getAreas($name){
        $Area = $this->model::where(['name' => $name])->get();

        return $Area;
    }

    public function getAllAreas($queryCriteria)
    {
        $limit = data_get($queryCriteria, 'limit', 10);
        $offset = data_get($queryCriteria, 'offset', 0);
        $sortBy = data_get($queryCriteria, 'sortBy', 'id');
        $sort = data_get($queryCriteria, 'sort', 'DESC');
        $filters = data_get($queryCriteria, 'filters', []);
   

        if (!empty($filters['city_name'])) {
            $query = $this->model->whereHas('city', function ($q) use ($filters) {
                $q->where('name', $filters['city_name']);
            });
        }
        
        if (!empty($filters['city_id'])) {
            $query =$this->model->where('city_id', $filters['city_id']);
        }
        return [
            'count' => $query->count(),
            'data' => $query->skip($offset)->take($limit)->orderBy($sortBy, $sort)->get(),
        ];
    }
}
