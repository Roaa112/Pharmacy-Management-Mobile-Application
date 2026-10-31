<?php

namespace App\Modules\Product;

use App\Models\Brand;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\HealthIssue;
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
        $search = trim((string) $request->query('search'));
        $categoryId = $request->integer('category') ?: null;
        $threshold = (int) config('pharmacy.low_stock_threshold');

        $products = Product::query()
            ->withoutGlobalScope('listing_aggregates')
            ->with(['brand:id,name_en', 'category:id,name_en', 'productImages'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($request->query('stock') === 'out', fn ($q) => $q->whereDoesntHave('sizes', fn ($s) => $s->where('stock', '>', 0)))
            ->when($request->query('stock') === 'low', fn ($q) => $q->whereHas('sizes', fn ($s) => $s->whereBetween('stock', [1, $threshold])))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('dashboard.Products.index', [
            'products' => $products,
            'search' => $search,
            'categories' => Category::orderBy('name_en')->get(['id', 'name_en', 'parent_id']),
            'threshold' => $threshold,
        ]);
    }

    public function create()
    {
        return view('dashboard.Products.form', $this->formData() + ['product' => new Product()]);
    }

    public function edit(Product $product)
    {
        $product->load('sizes', 'healthIssues:id', 'productImages');

        return view('dashboard.Products.form', $this->formData() + ['product' => $product]);
    }

    private function formData(): array
    {
        return [
            'brands' => Brand::orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'categories' => Category::whereNull('parent_id')->with('children:id,parent_id,name_en,name_ar')->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'healthIssues' => HealthIssue::orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'flashSales' => FlashSale::latest('date')->get(),
        ];
    }


    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();

        $this->productService->handleStoreProduct($data, $request);

        return redirect()->route('dashboard.products.index')->with('success', 'Product created successfully!');
    }



    public function destroy($id)
    {
        $this->productService->deleteProduct($id);
        return redirect()->back()->with('success', 'product deleted successfully!');
    }
}
