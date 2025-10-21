<?php

namespace App\Modules\Banner\Services;

use App\Models\Banner;

use App\Modules\Banner\Resources\BannerCollection;
use App\Modules\Banner\Repositories\BannersRepository;
use App\Modules\Banner\Requests\ListAllBannersRequest;

class BannerService
{
    public function __construct(private BannersRepository $bannersRepository)
    {
    }
  
    public function createBanner($request)
    {
        $banner = $this->constructBannerModel($request);
        return $this->bannersRepository->create($banner);
    }

    public function updateBanner($id, $request)
    {
       
        $banner = $this->constructBannerModel($request); 
      
        return $this->bannersRepository->update($id, $banner);
    }

    public function deleteBanner($id)
    {
        return $this->bannersRepository->delete($id);
    }

    public function listAllBanners(array $queryParameters)
    {
      
        $listAllBanners= (new ListAllBannersRequest)->constructQueryCriteria($queryParameters);
        $banners= $this->bannersRepository->findAllBy($listAllBanners );

        return [
            'data' => new BannerCollection($banners['data']),
            'count' => $banners['count']
        ];
    }

    public function getBannerById($id)
    {
        return $this->bannersRepository->find($id);
    }

    public function constructBannerModel($request)
    {
        $bannerModel = [
            'title_ar' => $request['title_ar'],
           
            'title_en' => $request['title_en'],
            'image' => $request['image'],
            'link' => $request['link'],
            'status' => $request['status'],
            'order_of_appearance' => $request['order_of_appearance'],
        ];
    
        return $bannerModel;
    }
    
   
  
}
