<?php

namespace App\Modules\Banner;

use App\Models\AdminAuditLog;
use App\Models\Banner;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Banner\Services\BannerService;
use App\Modules\Banner\Requests\StoreBannerRequest;
use App\Modules\Banner\Requests\UpdateBannerRequest;

class BannerController extends Controller
{
    public function __construct(private BannerService $bannerService)
    {
    }

    public function index(Request $request)
    {
        $banners = Banner::orderBy('position')->orderBy('order_of_appearance')->get();

        return view('dashboard.Banners.index', [
            'banners' => $banners,
        ]);
    }

    public function store(StoreBannerRequest $request)
    {
        $data = $request->validated();
        $data['image'] = MarketingImage::store($request->file('image'), 'banners');

        $banner = $this->bannerService->createBanner($data);
        AdminAuditLog::record('banner.created', $banner, $banner->toArray());

        return redirect()->back()->with('success', 'Banner created successfully!');
    }

    public function update(UpdateBannerRequest $request, $id)
    {
        $banner = Banner::findOrFail($id);
        $data = $request->validated();

        // A new upload replaces the image; otherwise the current one stays.
        $data['image'] = $request->hasFile('image') ? MarketingImage::store($request->file('image'), 'banners') : $banner->image;

        $this->bannerService->updateBanner($id, $data);
        AdminAuditLog::record('banner.updated', $banner, ['after' => $banner->fresh()->toArray()]);

        return redirect()->back()->with('success', 'Banner updated successfully!');
    }

    public function destroy($id)
    {
        AdminAuditLog::record('banner.deleted', Banner::findOrFail($id));
        $this->bannerService->deleteBanner($id);

        return redirect()->back()->with('success', 'Banner deleted successfully!');
    }
}
