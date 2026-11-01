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
            'link' => $request['link'] ?? null,
            'status' => (bool) ($request['status'] ?? false),
            'order_of_appearance' => $request['order_of_appearance'] ?? 0,
            'description_ar' => $request['description_ar'] ?? null,
            'description_en' => $request['description_en'] ?? null,
            'position' => $request['position'] ?? 'home',
            'device' => $request['device'] ?? 'all',
            'starts_at' => $request['starts_at'] ?? null,
            'ends_at' => $request['ends_at'] ?? null,
        ];
    
        return $bannerModel;
    }
    
   
  
}
