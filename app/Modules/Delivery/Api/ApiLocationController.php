<?php

namespace App\Modules\Delivery\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use Illuminate\Http\Request;

/**
 * Lists for address forms, so new app versions can send city_id / area_id. The store operates in
 * Egypt only: these are Egyptian governorates and areas, and there is no country to choose.
 */
class ApiLocationController extends Controller
{
    public function cities()
    {
        return response()->json(['data' => City::where('is_active', true)->where('country_id', Country::default()?->id)->orderBy('name')->get()
            ->map(fn (City $city) => ['id' => $city->id, 'name' => $city->display_name])]);
    }

    public function areas(Request $request)
    {
        $request->validate(['city_id' => 'required|integer']);

        return response()->json(['data' => Area::where('is_active', true)->where('city_id', $request->integer('city_id'))
            ->whereHas('city', fn ($q) => $q->where('country_id', Country::default()?->id))->orderBy('name')->get()
            ->map(fn (Area $area) => ['id' => $area->id, 'name' => (app()->getLocale() === 'ar' ? $area->name_ar : $area->name_en) ?: $area->name, 'city_id' => $area->city_id])]);
    }
}
