<?php

namespace App\Modules\OpeningAd\Services;

use App\Models\OpeningAd;

use App\Modules\OpeningAd\Resources\OpeningAdCollection;
use App\Modules\OpeningAd\Repositories\OpeningAdsRepository;
use App\Modules\OpeningAd\Requests\ListAllOpeningAdsRequest;

class OpeningAdService
{
    public function __construct(private OpeningAdsRepository $OpeningAdsRepository)
    {
    }

    public function createOpeningAd($request)
    {

        $OpeningAds = $this->constructOpeningAdModel($request);

        return $this->OpeningAdsRepository->create($OpeningAds);
    }

    public function updateOpeningAd($id, $request)
    {


        $OpeningAds = $this->constructOpeningAdModel($request);

        return $this->OpeningAdsRepository->update($id, $OpeningAds);
    }

    public function deleteOpeningAd($id)
    {
        return $this->OpeningAdsRepository->delete($id);
    }

    public function listAllOpeningAds(array $queryParameters)
    {

        $listAllOpeningAds= (new ListAllOpeningAdsRequest)->constructQueryCriteria($queryParameters);
        $OpeningAds= $this->OpeningAdsRepository->findAllBy($listAllOpeningAds );

        return [
            'data' => new OpeningAdCollection($OpeningAds['data']),
            'count' => $OpeningAds['count']
        ];
    }

    public function getOpeningAdById($id)
    {
        return $this->OpeningAdsRepository->find($id);
    }

    public function constructOpeningAdModel($request)
    {
        $OpeningAdModel = [

            'image' => $request['image'],
            'is_active' => $request['is_active'] ?? false,

        ];
        return $OpeningAdModel;
    }



}
