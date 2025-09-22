<?php

namespace App\Modules\Setting\Services;

use App\Models\Setting;

use App\Modules\Setting\Resources\SettingCollection;
use App\Modules\Setting\Repositories\SettingsRepository;
use App\Modules\Setting\Requests\ListAllSettingsRequest;

class SettingService
{
    public function __construct(private SettingsRepository $settingsRepository)
    {
    }

    public function createSetting($request)
    {
        $Setting = $this->constructSettingModel($request);
        return $this->settingsRepository->create($Setting);
    }

    public function updateSetting($id, $request)
    {

        $Setting = $this->constructSettingModel($request);

        return $this->settingsRepository->update($id, $Setting);
    }

    public function deleteSetting($id)
    {
        return $this->settingsRepository->delete($id);
    }

    public function listAllSettings(array $queryParameters)
    {

        $listAllSettings= (new ListAllSettingsRequest)->constructQueryCriteria($queryParameters);
        $Settings= $this->settingsRepository->findAllBy($listAllSettings );

        return [
            'data' => new SettingCollection($Settings['data']),
            'count' => $Settings['count']
        ];
    }

    public function getSettingById($id)
    {
        return $this->settingsRepository->find($id);
    }

  public function constructSettingModel($request)
{
    $SettingModel = [
        'privacy_policy_ar' => $request['privacy_policy_ar'],
        'privacy_policy_en' => $request['privacy_policy_en'],
        'terms_conditions_ar' => $request['terms_conditions_ar'],
        'terms_conditions_en' => $request['terms_conditions_en'],
        'facebook' => $request['facebook'],
        'instagram' => $request['instagram'],
        'tiktok' => $request['tiktok'],
        'youtube' => $request['youtube'],
        'phone_number' => $request['phone_number'],
        'map_location_url' => $request['map_location_url'],

        'spend_x' => $request['spend_x'],
        'get_y' => $request['get_y'],
        'zaincash' => $request['zaincash'],
    ];

    return $SettingModel;
}


}
