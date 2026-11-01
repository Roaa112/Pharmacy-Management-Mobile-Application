<?php

namespace App\Modules\OpeningAd;

use App\Models\OpeningAd;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ApiOpeningAdController extends Controller
{
    /**
     * Opening ads customers may see now (active, scheduled window, country, ?device=), highest
     * priority first. display_frequency tells the app how often to show each one.
     */
    public function listAllOpeningAds(Request $request)
    {
        $request->validate(['device' => 'nullable|string|max:20']);

        $ads = OpeningAd::visible($request->query('device'))
            ->orderByDesc('priority')->orderByDesc('id')
            ->limit(20)
            ->get();

        return successJsonResponse($ads, __('OpeningAds.success.get_all_brands'), $ads->count());
    }
}
