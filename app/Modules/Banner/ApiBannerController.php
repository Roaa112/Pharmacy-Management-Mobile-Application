<?php

namespace App\Modules\Banner;

use App\Models\Banner;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Banner\Services\BannerService;
use App\Modules\Banner\Requests\ListAllBannersRequest;


class ApiBannerController extends Controller
{
    public function __construct(private BannerService $bannerService)
    {
    }
    public function listAllBanners(ListAllBannersRequest $request)
    {
 
        $Banners= $this->bannerService->listAllBanners($request->all());
        return successJsonResponse(
            data_get($Banners, 'data'),
            __('Banners.success.get_all_brands'),
            data_get($Banners, 'count')
        );
    }
    
  
    
  
}
