<?php

namespace App\Modules\Category\Repositories;

use App\Models\Category;
use App\Modules\Shared\Repositories\BaseRepository;

class CategoriesRepository extends BaseRepository
{
    public function __construct(private Category $model)
    {
        parent::__construct($model);
    }

    public function findAllBy($queryCriteria = [])
    {
        $query = Category::with('subcategories')->whereNull('parent_id');
        $categories = $query->get();
        return [
            'data' => $categories,
            'count' => $categories->count()
        ];
    }
     
    public function createSub(array $categoryData)
    {

        return Category::create($categoryData);
    }
    
}