<?php

namespace App\Modules\Brand;

use App\Models\Brand;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Brand\Services\BrandService;
use App\Modules\Brand\Requests\StoreBrandRequest;
use App\Modules\Brand\Requests\UpdateBrandRequest;

class BrandController extends Controller
{
    public function __construct(private BrandService $brandService)
    {
    }

    public function index(Request $request)
    {
        $Brands = $this->brandService->listAllBrands($request->all());
    
        return view('dashboard.Brands.index', [
            'Brands' => $Brands['data'], 
        ]);
    }
    

    public function store(StoreBrandRequest $request)
    {
    
     $data = $request->validated();

    if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('brands'), $filename);
    $data['image'] = 'brands/' . $filename;
}


        $this->brandService->createBrand ( $data );
        return redirect()->back()->with('success', 'Brand created successfully!');
    }
  
    public function update(UpdateBrandRequest $request, $id)
    {
    $brand = Brand::findOrFail($id);
    $data = $request->validated();

   
    
        // Check if a new image is uploaded
       if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('brands'), $filename);
    $data['image'] = 'brands/' . $filename;
}else {
            // Retain the old image if no new image is uploaded
            $data['image'] = $brand->image;
        }

        $this->brandService->updateBrand($id, $data);
        return redirect()->back()->with('success', 'Brand updated successfully!');
    }

    public function destroy($id)
    {
        $this->brandService->deleteBrand($id);
        return redirect()->back()->with('success', 'Brand deleted successfully!');
    }
}
