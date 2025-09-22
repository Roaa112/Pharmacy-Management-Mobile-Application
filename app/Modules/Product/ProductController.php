<?php

namespace App\Modules\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Modules\Brand\Services\BrandService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Category\Services\CategoryService;
use App\Modules\Discount\Services\DiscountService;
use App\Modules\FlashSale\Services\FlashSaleService;
use App\Modules\Product\Requests\StoreProductRequest;
use App\Modules\Product\Requests\UpdateProductRequest;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Modules\HealthIssue\Services\HealthIssueService;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService ,BrandService  $brandService,HealthIssueService $healthIssueService,  CategoryService  $categoryService, DiscountService $discountService ,FlashSaleService $flashSaleService)
    {
        $this->productService = $productService;
        $this->brandService = $brandService;
        $this->healthIssueService = $healthIssueService;
        $this->categoryService = $categoryService;
        $this->flashSaleService = $flashSaleService;
        // $this->discountService = $discountService;
    }
   public function update(UpdateProductRequest $request, Product $product): RedirectResponse
{
    $this->productService->updateProduct($product, $request);
    return redirect()->back()->with('success', 'Product updated successfully!');
}

    public function index(Request $request)
    {

        $products = $this->productService->listAllProducts($request->all());
        $brands = $this->brandService->listAllBrands($request->all());
        $healthIssues = $this->healthIssueService->listAllHealthIssues($request->all());
        $categories = $this->categoryService->listAllCategories($request->all());
        $flashSales = $this->flashSaleService->listAllFlashSales($request->all());
        // $discounts = $this->discountService->listAllDiscounts($request->all());


        return view('dashboard.Products.index', [
            'products' => $products['data'],
            'brands' => $brands['data'],
            'healthIssues' =>  $healthIssues['data'],
            'categories' =>  $categories['data'],
            'flashSales' =>  $flashSales['data'],
            // 'discounts' =>  $discounts['data'],
        ]);
    }


    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();

        $this->productService->handleStoreProduct($data, $request);

        return redirect()->back()->with('success', 'Product created successfully!');
    }



    public function destroy($id)
    {
        $this->productService->deleteProduct($id);
        return redirect()->back()->with('success', 'product deleted successfully!');
    }
}
