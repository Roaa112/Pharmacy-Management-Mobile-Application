<?php

namespace App\Modules\Category\Services;

use App\Models\Category;

use App\Modules\Category\Resources\categoryCollection;
use App\Modules\Category\Repositories\CategoriesRepository;

use App\Modules\Category\Requests\ListAllcategoriesRequest;

class CategoryService
{
    public function __construct(private CategoriesRepository $categoriesRepository)
    {
    }
  
    public function createCategory($request)
    {
        $category = $this->constructCategoryModel($request);
        return $this->categoriesRepository->create($category);
    }
    public function createSubcategory($request, Category $category)
    {
    
        $subcategory = $this->constructCategoryModel($request, $category); 
  
        return $this->categoriesRepository->createSub($subcategory);
    }

    public function updateCategory($id, $request)
    {
       
        $category = $this->constructCategoryModel($request); 
      
        return $this->categoriesRepository->update($id, $category);
    }

    public function deletecategory($id)
    {
        return $this->categoriesRepository->delete($id);
    }

    public function listAllCategories(array $queryParameters)
    {
      
        $listAllcategories = (new ListAllcategoriesRequest)->constructQueryCriteria($queryParameters);
        $categories = $this->categoriesRepository->findAllBy($listAllcategories);

        return [
            'data' => new categoryCollection($categories['data']),
            'count' => $categories['count']
        ];
    }

    public function getcategoryById($id)
    {
        return $this->categoriesRepository->find($id);
    }

    public function constructCategoryModel($request)
    {
        $categoryModel = [
            'name_en' => $request['name_en'],
            'name_ar' => $request['name_ar'],
             'image' => $request['image'],
            'parent_id' => $request['parent_id'] ?? null,
            
        ];
        return $categoryModel;
    }
    
   
  
}
