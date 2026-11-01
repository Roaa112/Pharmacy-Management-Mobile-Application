<?php

namespace App\Modules\Setting;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Setting;
use App\Modules\Setting\Requests\StoreSettingRequest;
use App\Modules\Setting\Requests\UpdateSettingRequest;
use App\Modules\Setting\Services\SettingService;

/**
 * The settings singleton. There is no create or delete: store() and update() both save into
 * Setting::current(), so a second row can never appear.
 */
class SettingController extends Controller
{
    public function __construct(private SettingService $settingService)
    {
    }

    public function index()
    {
        return view('dashboard.Settings.index', [
            'setting' => Setting::current(),
        ]);
    }

    public function store(StoreSettingRequest $request)
    {
        return $this->save($request->validated());
    }

    public function update(UpdateSettingRequest $request, $id)
    {
        return $this->save($request->validated());
    }

    private function save(array $data)
    {
        $setting = Setting::current();
        $before = $setting->only(array_keys($data));

        $this->settingService->updateSetting($setting->id, $data);

        AdminAuditLog::record('settings.update', $setting, ['before' => $before, 'after' => $setting->fresh()->only(array_keys($data))]);

        return redirect()->back()->with('success', 'Settings saved.');
    }
}
