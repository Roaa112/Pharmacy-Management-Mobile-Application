<?php

namespace App\Modules\OpeningAd;

use App\Models\OpeningAd;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\OpeningAd\Services\OpeningAdService;
use App\Modules\OpeningAd\Requests\ListAllOpeningAdsRequest;


class ApiOpeningAdController extends Controller
{
    public function __construct(private OpeningAdService $OpeningAdService)
    {
    }
    public function listAllOpeningAds(ListAllOpeningAdsRequest $request)
    {

        $OpeningAd = $this->OpeningAdService->listAllOpeningAds($request->all());
        return successJsonResponse(
            data_get($OpeningAd, 'data'),
            __('OpeningAds.success.get_all_brands'),
            data_get($OpeningAd, 'count')
        );
    }


}
