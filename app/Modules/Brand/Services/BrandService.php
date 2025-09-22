<?php

namespace App\Modules\Brand\Services;

use App\Models\Brand;

use App\Modules\Brand\Resources\BrandCollection;
use App\Modules\Brand\Repositories\BrandsRepository;
use App\Modules\Brand\Requests\ListAllBrandsRequest;

class BrandService
{
    public function __construct(private BrandsRepository $BrandsRepository)
    {
    }
  
    public function createBrand($request)
    {
     
        $brand = $this->constructBrandModel($request);
      
        return $this->BrandsRepository->create($brand);
    }

    public function updateBrand($id, $request)
    {
 
       
        $brand = $this->constructBrandModel($request); 
      
        return $this->BrandsRepository->update($id, $brand);
    }

    public function deleteBrand($id)
    {
        return $this->BrandsRepository->delete($id);
    }

    public function listAllBrands(array $queryParameters)
    {
      
        $listAllbrands= (new ListAllBrandsRequest)->constructQueryCriteria($queryParameters);
        $brands= $this->BrandsRepository->findAllBy($listAllbrands );

        return [
            'data' => new BrandCollection($brands['data']),
            'count' => $brands['count']
        ];
    }

    public function getBrandById($id)
    {
        return $this->BrandsRepository->find($id);
    }

    public function constructBrandModel($request)
    {
        $brandModel = [
            'name_en' => $request['name_en'],
            'name_ar' => $request['name_ar'],
            'image' => $request['image'],
          
            
        ];
        return $brandModel;
    }
    
   
  
}
