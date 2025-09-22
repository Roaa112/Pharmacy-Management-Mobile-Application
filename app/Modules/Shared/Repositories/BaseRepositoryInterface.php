<?php

namespace App\Modules\Shared\Repositories;

interface BaseRepositoryInterface
{
    public function create(array $attributes);
    public function update(int $id, array $attributes);
    public function updateOrCreate(array $conditions, array $data);
    public function find(int $id);
    public function findOneBy(array $parameters);
    public function findAllBy(array $parameters);
    public function executeGetSingle($query);
    public function executeGetMany($query, array $queryCriteria);
}
