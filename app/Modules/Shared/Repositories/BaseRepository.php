<?php

namespace App\Modules\Shared\Repositories;

use App\Modules\Shared\Repositories\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Model;

class BaseRepository implements BaseRepositoryInterface
{
    public function __construct( private Model $model ) {
        $this->model = $model;
    }
public function getModel(): Model
{
    return $this->model;
}

    public function create($attributes) : Model
    {

        return $this->model->create($attributes);
    }

    public function update($id, array $attributes)
    {
        $model = $this->model->where('id', $id)->get()->first();
        if(!$model){
            return null;
        }
        $model->update($attributes);
        return $model;
    }

    public function delete($id)
    {
        $model = $this->model->where('id', $id)->get()->first();
        if(!$model){
            return null;
        }
        $model->delete();
        return $model;
    }

    public function updateOrCreate($conditions, $data)
    {
        return $this->model->updateOrCreate($conditions, $data);
    }

    public function firstOrCreate($conditions, $data)
    {
        return $this->model->firstOrCreate($conditions, $data);
    }

    public function find($id): ?Model
    {
        return $this->model->find($id);
    }

    public function findOneBy($parameters, $selectedFields = [])
    {
        $query = $this->model->where($parameters);
        if(count($selectedFields)){
            $query->select($selectedFields);
        }
        return $query->first();
    }

    public function findAllBy($queryCriteria = [])
    {
        return $this->executeGetMany($this->model, $queryCriteria);
    }

    public function findAllByLikeFilters($queryCriteria = [])
    {
        return $this->executeGetManyWithLike($this->model, $queryCriteria);
    }

    public function executeGetSingle($query)
    {
        return $query->first();
    }

    public function executeGetMany($query, $queryCriteria = [])
    {
        $limit = data_get($queryCriteria, 'limit', 10);
        $offset = data_get($queryCriteria, 'offset', 0);
        $sortBy = data_get($queryCriteria, 'sortBy', 'id');
        $sort = data_get($queryCriteria, 'sort', 'DESC');
        $filters = data_get($queryCriteria, 'filters', []);
        if (!empty($filters)) {
            $query = $query->where($filters);
        }
        return [
            'count' => $query->count(),
            'data' => $query->skip($offset)->take($limit)->orderBy($sortBy, $sort)->get(),
        ];
    }

    public function executeGetManyWithLike($query, $queryCriteria = [])
    {
        $limit = data_get($queryCriteria, 'limit', 10);
        $offset = data_get($queryCriteria, 'offset', 0);
        $sortBy = data_get($queryCriteria, 'sortBy', 'id');
        $sort = data_get($queryCriteria, 'sort', 'DESC');
        $filters = data_get($queryCriteria, 'filters', []);
        if (!empty($filters)) {
            $query = $query->whereLike($filters);
        }
        return [
            'count' => $query->count(),
            'data' => $query->skip($offset)->take($limit)->orderBy($sortBy, $sort)->get(),
        ];
    }

    public function getTotalRecordsCount($filters)
    {
        $query = $this->model;
        if ($filters) {
            $query = $query->where($filters);
        }
        return $query->count();
    }

    public function bulkInsert($models)
    {
        return $this->model->insert($models);
    }
}
