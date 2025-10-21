<?php

namespace App\Modules\OpeningAd;

use App\Models\OpeningAd;
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
        $OpeningAds = $this->OpeningAdService->listAllOpeningAds($request->all());

        return view('dashboard.OpeningAds.index', [
            'OpeningAds' => $OpeningAds['data'],
        ]);
    }


   public function store(StoreOpeningAdRequest $request)
{

    $data = $request->validated();


    if ($request->hasFile('image')) {

        $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();


        $path = $request->file('image')->storeAs('OpeningAdS', $filename, 'public');


        $data['image'] = 'storage/' . $path;
    }


    $this->OpeningAdService->createOpeningAd($data);


    return redirect()->back()->with('success', 'OpeningAd created successfully!');
}


    public function update(UpdateOpeningAdRequest $request, $id)
    {
    $OpeningAd = OpeningAd::findOrFail($id);
    $data = $request->validated();



        // Check if a new image is uploaded
       if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('OpeningAdS'), $filename);
    $data['image'] = 'OpeningAdS/' . $filename;
}else {
            // Retain the old image if no new image is uploaded
            $data['image'] = $OpeningAd->image;
        }

        $this->OpeningAdService->updateOpeningAd($id, $data);
        return redirect()->back()->with('success', 'OpeningAd updated successfully!');
    }

    public function destroy($id)
    {
        $this->OpeningAdService->deleteOpeningAd($id);
        return redirect()->back()->with('success', 'OpeningAd deleted successfully!');
    }
}
