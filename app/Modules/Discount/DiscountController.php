<?php

namespace App\Modules\Discount;

use App\Models\Discount;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Brand\Services\BrandService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Category\Services\CategoryService;
use App\Modules\Discount\Services\DiscountService;
use App\Modules\Discount\Requests\StoreDiscountRequest;
use App\Modules\Discount\Requests\UpdateDiscountRequest;

class DiscountController extends Controller
{
    public function __construct(private DiscountService $discountService, CategoryService  $categoryService, ProductService $productService ,BrandService  $brandService)
    {
              $this->categoryService = $categoryService;
               $this->productService = $productService;
        $this->brandService = $brandService;
    }

   public function index(Request $request)
{
    $discounts = $this->discountService->getAll();
    $brands = $this->brandService->listAllBrands($request->all());
    $products = $this->productService->listAllProducts($request->all());
    $categories = $this->categoryService->listAllCategories($request->all());

    return view('dashboard.Discounts.index', [
        'discounts'  => $discounts,
        'brands'     => $brands['data'],
        'products'   => $products['data'],
        'categories' => $categories['data'],
    ]);
}


    public function create()
    {
        return view('dashboard.Discounts.create');
    }

//  public function store(Request $request)
// {

//     $$discount = $request->all();

//     foreach ($request->input('targets', []) as $target) {
//     $discount->targets()->create([
//         'type' => $target['type'],
//         'target_id' => $target['id'],
//     ]);
// }

// foreach ($request->input('gift_targets', []) as $giftTarget) {
//     $discount->targets()->create([
//         'type' => $giftTarget['type'],
//         'target_id' => $giftTarget['id'],
//         'is_gift' => true,
//     ]);
// }

//     $this->discountService->create($$discount);

//     return redirect()->route('dashboard.discounts.index')->with('success', 'Discount created successfully!');
// }

public function store(Request $request)
{


if (!in_array($request->discount_type, ['buy_x_get_y', 'amount_gift'])) {
    $request->request->remove('gift_targets');
}


    $request->merge([
        'applies_to_type' => $request->input('targets.0.type'),
        'applies_to_id'   => $request->input('targets.0.id'),
    ]);

     $discountData = $request->all();
    $this->discountService->create($discountData);

    return redirect()->route('dashboard.discounts.index')->with('success', 'Discount created successfully!');
}




    public function edit($id)
    {
        $discount = $this->discountService->getById($id);
        return view('dashboard.Discounts.edit', compact('discount'));
    }

    public function update(Request $request, $id)
    {
          $validatedData = $request->validate([

        'starts_at' => 'nullable|date',
        'ends_at' => 'nullable|date|after:starts_at',

    ]);
        $this->discountService->update($id, $validatedData);
        return redirect()->route('dashboard.discounts.index')->with('success', 'Discount updated successfully!');
    }

    public function destroy($id)
    {
        $this->discountService->delete($id);
        return redirect()->route('dashboard.discounts.index')->with('success', 'Discount deleted successfully!');
    }
}
