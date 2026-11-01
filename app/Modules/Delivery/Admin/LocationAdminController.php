<?php

namespace App\Modules\Delivery\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use Illuminate\Http\Request;

/** Cities (governorates) and their areas, used by addresses and delivery zones. */
class LocationAdminController extends Controller
{
    public function index(Request $request)
    {
        // Egyptian governorates (the store operates in Egypt only).
        return view('dashboard.locations.index', [
            'cities' => City::with('areas')->withCount('areas')->where('country_id', Country::default()?->id)->orderBy('name')->get(),
        ]);
    }

    public function storeCity(Request $request)
    {
        $city = City::create($this->cityData($request));
        AdminAuditLog::record('city.create', $city, $city->toArray());

        return redirect()->back()->with('success', 'City created.');
    }

    public function updateCity(Request $request, City $city)
    {
        $city->update($this->cityData($request));
        AdminAuditLog::record('city.update', $city, $city->getChanges());

        return redirect()->back()->with('success', 'City saved.');
    }

    public function storeArea(Request $request, City $city)
    {
        $area = $city->areas()->create($this->areaData($request));
        AdminAuditLog::record('area.create', $area, $area->toArray());

        return redirect()->back()->with('success', 'Area created.');
    }

    public function updateArea(Request $request, Area $area)
    {
        $area->update($this->areaData($request));
        AdminAuditLog::record('area.update', $area, $area->getChanges());

        return redirect()->back()->with('success', 'Area saved.');
    }

    private function cityData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'name_en' => 'nullable|string|max:255',
        ]) + ['is_active' => $request->boolean('is_active', true), 'country_id' => Country::default()?->id];
    }

    private function areaData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'name_en' => 'nullable|string|max:255',
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
