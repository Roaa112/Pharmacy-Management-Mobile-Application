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
            'title_en' => $request['title_en'] ?? null,
            'title_ar' => $request['title_ar'] ?? null,
            'content_en' => $request['content_en'] ?? null,
            'content_ar' => $request['content_ar'] ?? null,
            'link' => $request['link'] ?? null,
            'display_frequency' => $request['display_frequency'] ?? 'every_open',
            'priority' => $request['priority'] ?? 0,
            'device' => $request['device'] ?? 'all',
            'starts_at' => $request['starts_at'] ?? null,
            'ends_at' => $request['ends_at'] ?? null,
        ];
        return $OpeningAdModel;
    }



}
