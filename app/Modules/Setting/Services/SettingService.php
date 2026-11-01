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
        return $this->settingsRepository->update($id, $this->constructSettingModel($request));
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

    /** Only the fields that were sent are written, so saving one section never clears another. */
    public function constructSettingModel($request)
    {
        return array_intersect_key($request, array_flip((new Setting())->getFillable()));
    }
}
