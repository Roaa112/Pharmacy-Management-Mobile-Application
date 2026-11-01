<?php

namespace App\Modules\OpeningAd;

use App\Models\AdminAuditLog;
use App\Models\OpeningAd;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\OpeningAd\Services\OpeningAdService;
use App\Modules\OpeningAd\Requests\StoreOpeningAdRequest;
use App\Modules\OpeningAd\Requests\UpdateOpeningAdRequest;

class OpeningAdController extends Controller
{
    public function __construct(private OpeningAdService $OpeningAdService)
    {
    }

    public function index(Request $request)
    {
        $OpeningAds = OpeningAd::orderByDesc('priority')->latest()->get();

        return view('dashboard.OpeningAds.index', [
            'OpeningAds' => $OpeningAds,
        ]);
    }

    public function store(StoreOpeningAdRequest $request)
    {
        $data = $request->validated();
        $data['image'] = MarketingImage::store($request->file('image'), 'opening-ads');

        $ad = $this->OpeningAdService->createOpeningAd($data);
        AdminAuditLog::record('opening_ad.created', $ad, $ad->toArray());

        return redirect()->back()->with('success', 'OpeningAd created successfully!');
    }

    public function update(UpdateOpeningAdRequest $request, $id)
    {
        $OpeningAd = OpeningAd::findOrFail($id);
        $data = $request->validated();

        // A new upload replaces the image; otherwise the current one stays.
        $data['image'] = $request->hasFile('image') ? MarketingImage::store($request->file('image'), 'opening-ads') : $OpeningAd->image;

        $this->OpeningAdService->updateOpeningAd($id, $data);
        AdminAuditLog::record('opening_ad.updated', $OpeningAd, ['after' => $OpeningAd->fresh()->toArray()]);

        return redirect()->back()->with('success', 'OpeningAd updated successfully!');
    }

    public function destroy($id)
    {
        AdminAuditLog::record('opening_ad.deleted', OpeningAd::findOrFail($id));
        $this->OpeningAdService->deleteOpeningAd($id);

        return redirect()->back()->with('success', 'OpeningAd deleted successfully!');
    }
}
