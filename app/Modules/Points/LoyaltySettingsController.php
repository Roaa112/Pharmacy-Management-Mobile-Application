<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Setting;
use App\Modules\Points\Requests\UpdateLoyaltySettingsRequest;
use App\Modules\Points\Services\LoyaltySettings;

/** Loyalty › Settings: every earning, expiry and redemption rule (loyalty.settings). */
class LoyaltySettingsController extends Controller
{
    public function edit()
    {
        return view('dashboard.loyalty.settings', ['s' => Setting::current(), 'rules' => LoyaltySettings::current()]);
    }

    public function update(UpdateLoyaltySettingsRequest $request)
    {
        $setting = Setting::current();
        $data = $request->settings();
        $before = $setting->only(array_keys($data));

        $setting->update($data);

        AdminAuditLog::record('loyalty.settings', $setting, ['before' => $before, 'after' => $setting->fresh()->only(array_keys($data))]);

        // Rules apply to points earned from now on; existing lots keep their dates.
        return redirect()->back()->with('success', 'Loyalty settings saved. They apply to points earned and redeemed from now on.');
    }
}
