<?php

namespace App\Modules\Brand;

use App\Models\Brand;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Brand\Services\BrandService;
use App\Modules\Brand\Requests\ListAllBrandsRequest;



class ApiBrandController extends Controller
{
    public function __construct(private BrandService $brandService)
    {
    }
   public function bestSellingProductsByBrand(Request $request, $brandId)
{

    // تحقق من وجود البراند
    $brand = Brand::find($brandId);
    if (!$brand) {
        return response()->json([
            'status' => false,
            'message' => 'Brand not found',
        ], 404);
    }

    // جلب IDs المنتجات المرتبطة بالبراند
    $productIds = $brand->products()->pluck('id')->toArray();
    if (empty($productIds)) {
        return response()->json([
            'status' => true,
            'message' => 'No products found for this brand',
            'data' => [],
        ]);
    }

    // المنتجات الأعلى مبيعًا حسب كمية الطلبات
    $bestProducts = OrderItem::select('product_id', DB::raw('SUM(quantity) as total_sold'))
        ->whereIn('product_id', $productIds)
        ->groupBy('product_id')
        ->orderByDesc('total_sold')
        ->get();

    // ترتيب الـ product_ids حسب الكمية
    $productIdsOrdered = $bestProducts->pluck('product_id')->toArray();

    // جلب بيانات المنتجات
    $products = Product::whereIn('id', $productIdsOrdered)
        ->with(['category', 'brand', 'productImages', 'sizes'])
        ->get();

    // تنسيق البيانات
    $productsData = collect($productIdsOrdered)->map(function ($productId) use ($products, $bestProducts) {
        $product = $products->firstWhere('id', $productId);
        if (!$product) return null;

        $sold = $bestProducts->firstWhere('product_id', $productId)?->total_sold ?? 0;

        $productArray = $product->toArray();
        $productArray['total_sold'] = (int) $sold;

        return $productArray;
    })->filter()->values();

    return response()->json([
        'status' => true,
        'message' => 'Best selling products for this brand fetched successfully',
        'data' => $productsData,
    ]);
}
public function newArrivalProductsByBrand(Request $request, $brandId)
{
    // تحقق من وجود البراند
    $brand = Brand::find($brandId);
    if (!$brand) {
        return response()->json([
            'status' => false,
            'message' => 'Brand not found',
        ], 404);
    }

    // جلب المنتجات المرتبطة بالبراند مرتبة من الأحدث
    $products = $brand->products()
        ->with(['category', 'brand', 'sizes', 'productImages'])
        ->orderByDesc('created_at')
        ->get();

    $productsData = $products->map(function ($product) {
        $productArray = $product->toArray();
        $productArray['created_at'] = $product->created_at->toDateTimeString();
        return $productArray;
    });

    return response()->json([
        'status' => true,
        'message' => 'New arrival products for this brand fetched successfully',
        'data' => $productsData,
    ]);
}

    public function listAllBrands(ListAllBrandsRequest $request)
    {

        $brands = $this->brandService->listAllBrands($request->all());
        return successJsonResponse(
            data_get($brands, 'data'),
            __('Brands.success.get_all_brands'),
            data_get($brands, 'count')
        );
    }

public function listAllBrandsProducts($id)
{
    $limit = request()->get('limit', 10);
    $offset = request()->get('offset', 0);

    $brand = Brand::find($id);

    if (!$brand) {
        return errorJsonResponse(__('Brands.errors.not_found'), 404);
    }

    // Load products with relations (no need for manual discount logic)
    $products = $brand->products()
        ->with(['saleable', 'category', 'sizes'])
        ->offset($offset)
        ->limit($limit)
        ->get();

    // Just add extra data without recalculating any prices
    $products = $products->map(function ($product) {
        $productArray = $product->toArray();
        $productArray['category_name'] = $product->category->name ?? null;
        return $productArray;
    });

    // Attach products to the brand before returning
    $brand->setRelation('products', $products);

    return successJsonResponse(
        $brand,
        __('Brands.success.get_brand_with_products'),
        $products->count()
    );
}

}
