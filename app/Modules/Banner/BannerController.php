<?php

namespace App\Modules\Banner;

use App\Models\Banner;
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
        $banners = $this->bannerService->listAllBanners($request->all());
    
        return view('dashboard.Banners.index', [
            'banners' => $banners['data'], 
        ]);
    }
    

    public function store(StoreBannerRequest $request)
    {
        
        $data = $request->validated();
      if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('banners'), $filename);
    $data['image'] = 'banners/' . $filename;
}

        $this->bannerService->createBanner($data);
        return redirect()->back()->with('success', 'Banner created successfully!');
    }
  
    public function update(UpdateBannerRequest $request, $id)
    {
      
        $banner = Banner::findOrFail($id);
    
        $data = $request->validated();
    
        // Check if a new image is uploaded
       if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('banners'), $filename);
    $data['image'] = 'banners/' . $filename;
}else {
            // Retain the old image if no new image is uploaded
            $data['image'] = $banner->image;
        }

    
        // Update the banner with new data
        $this->bannerService->updateBanner($id, $data);
    
        // Redirect back with success message
        return redirect()->back()->with('success', 'Banner updated successfully!');
    }
    

    public function destroy($id)
    {
        $this->bannerService->deleteBanner($id);
        return redirect()->back()->with('success', 'Banner deleted successfully!');
    }
}
