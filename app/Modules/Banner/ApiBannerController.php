<?php

namespace App\Modules\Banner;

use App\Models\Banner;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ApiBannerController extends Controller
{
    /**
     * Banners customers may see now: active, inside their dates (store clock), for the store's
     * country and, when the app sends ?device=ios|android|web, for that device. Optional
     * ?position= filters by placement.
     */
    public function listAllBanners(Request $request)
    {
        $request->validate([
            'device' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:30',
        ]);

        $banners = Banner::visible($request->query('device'))
            ->when($request->filled('position'), fn ($q) => $q->where('position', $request->query('position')))
            ->orderBy('order_of_appearance')->orderBy('id')
            ->limit(100)
            ->get();

        return successJsonResponse($banners, __('Banners.success.get_all_brands'), $banners->count());
    }
}
