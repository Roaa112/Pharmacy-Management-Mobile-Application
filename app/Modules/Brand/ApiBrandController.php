<?php

namespace App\Modules\Brand;

use App\Models\Brand;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Brand\Services\BrandService;
use App\Modules\Brand\Requests\ListAllBrandsRequest;


class ApiBrandController extends Controller
{
    public function __construct(private BrandService $brandService)
    {
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
