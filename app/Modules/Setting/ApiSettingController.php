<?php

namespace App\Modules\Setting;

use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Setting\Services\SettingService;
use App\Modules\Setting\Requests\ListAllSettingsRequest;


class ApiSettingController extends Controller
{
    public function __construct(private SettingService $settingService)
    {
    }
    public function listAllSettings(ListAllSettingsRequest $request)
    {
        $Settings = $this->settingService->listAllSettings($request->all());
        return successJsonResponse(
            data_get($Settings, 'data'),
            __('Settings.success.get_all_brands'),
            data_get($Settings, 'count')
        );
    }
  
    
    
    
}
