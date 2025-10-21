<?php

namespace App\Modules\Setting;

use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Setting\Services\SettingService;
use App\Modules\Setting\Requests\StoreSettingRequest;
use App\Modules\Setting\Requests\UpdateSettingRequest;

class SettingController extends Controller
{
    public function __construct(private SettingService $settingService)
    {
    }

    public function index(Request $request)
    {
        $settings = $this->settingService->listAllSettings($request->all());
    
        return view('dashboard.Settings.index', [
            'settings' => $settings['data'], 
        ]);
    }
    

    public function store(StoreSettingRequest $request)
    {

        $this->settingService->createSetting($request->validated());
        return redirect()->back()->with('success', 'Setting created successfully!');
    }
  
    public function update(UpdateSettingRequest $request, $id)
    {
     
        $this->settingService->updateSetting($id, $request->validated());
        return redirect()->back()->with('success', 'Setting updated successfully!');
    }

    public function destroy($id)
    {
        $this->settingService->deleteSetting($id);
        return redirect()->back()->with('success', 'Setting deleted successfully!');
    }
}
