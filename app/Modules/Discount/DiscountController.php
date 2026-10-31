<?php

namespace App\Modules\Discount;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    // Full lists (the listing services stop at 10 rows).
    return view('dashboard.Discounts.index', [
        'discounts'  => $discounts,
        'brands'     => Brand::orderBy('name_en')->pluck('name_en', 'id'),
        'products'   => Product::basic()->orderBy('name_en')->pluck('name_en', 'id'),
        'categories' => Category::orderBy('name_en')->pluck('name_en', 'id'),
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
    $request->validate([
        'discount_type' => 'required|in:fixed,percent,buy_x_get_y,amount_gift',
        'discount_value' => ['nullable', 'required_if:discount_type,fixed,percent', 'numeric', 'min:0',
            Rule::when($request->input('discount_type') === 'percent', 'max:100')],
        'min_quantity' => 'nullable|required_if:discount_type,buy_x_get_y|integer|min:1',
        'free_quantity' => 'nullable|integer|min:0',
        'min_amount' => 'nullable|required_if:discount_type,amount_gift|numeric|min:0',
        'targets' => 'required|array|min:1',
        'targets.*.type' => 'required|in:product,brand,category',
        'targets.*.id' => 'required|integer|min:1',
        'gift_targets' => 'nullable|required_if:discount_type,buy_x_get_y,amount_gift|array',
        'gift_targets.*.type' => 'required|in:product,brand,category',
        'gift_targets.*.id' => 'required|integer|min:1',
        // A rule without dates never becomes active (checkout and pricing filter on them).
        'starts_at' => 'required|date',
        'ends_at' => 'required|date|after:starts_at',
    ]);

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
        // Dates are edited from the list; there is no separate edit page.
        return redirect()->route('dashboard.discounts.index');
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
