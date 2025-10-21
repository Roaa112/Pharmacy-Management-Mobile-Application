<?php

namespace App\Modules\Product;

use App\Models\Product;
use App\Models\OrderItem;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Product\Services\ProductService;
use App\Modules\Product\Repositories\ProductsRepository;
use App\Modules\Product\Requests\ListAllProductsRequest;


class ApiProductController extends Controller
{
    public function __construct(private ProductService $productService ,private ProductsRepository $productRepository)
    {
    }
 public function listAllProducts(ListAllProductsRequest $request)
{
    $products = $this->productService->listAllProducts($request->all());

    return successJsonResponse(
        $products['data'],
        __('Products.success.get_all'),
        $products['count']
    );
}


    public function listAllProductsOnSale(ListAllProductsRequest $request)
    {
        $products = $this->productService->listAllProductsOnSale($request);



        return successJsonResponse(
            $products['data'],
            __('Products.success.get_all_brands')
        );
    }


    public function showProduct($id)
    {
        // جلب المنتج باستخدام لـ ID
        $product = Product::with(['saleable', 'category'])->find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }




        return response()->json([
            'data' => $product,
        ], 200);
    }

    public function searchProducts(Request $request)
    {
        $queryCriteria = $request->all();


        if (!isset($queryCriteria['search'])) {
            return response()->json([
                'message' => 'Search term is required.',
            ], 400);
        }

        // مناداة دالة البث في الـ Service
        $products = $this->productService->searchProducts($queryCriteria);

        return response()->json([
            'data' => $products['data'],
            'count' => $products['count'],
        ]);
    }

    public function latestProducts(Request $request)
    {
        $products = $this->productService->getLatestProducts($request);

        return successJsonResponse(
            $products['data'],
            __('Products.success.get_latest'),
            $products['count']
        );
    }
    public function topDiscountProducts(Request $request)
    {
        $products = $this->productService->getTopDiscountProducts($request);

        return successJsonResponse(
            $products['data'],
            __('Products.success.get_top_discount'),
            $products['count']
        );
    }




    public function relatedProducts($productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.'
            ], 404);
        }

        $related = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with(['reviews', 'category'])
            ->take(10)
            ->get();

        return response()->json([
            'data' => $related
        ]);
    }



   public function productSell(Request $request)
    {
        $limit = (int) $request->get('limit', 10);
        $offset = (int) $request->get('offset', 0);
        $sort = strtoupper($request->get('sort', 'DESC'));
        $sortBy = $request->get('sortBy', 'total_sold');

        // Whitelist of sortable columns to prevent SQL injection
        $allowedSortBy = ['total_sold', 'product_id', 'id'];
        if (!in_array($sortBy, $allowedSortBy)) {
            $sortBy = 'total_sold';
        }

        // Enforce only ASC or DESC
        if (!in_array($sort, ['ASC', 'DESC'])) {
            $sort = 'DESC';
        }

        $topSelling = OrderItem::select(
            'product_id',
            DB::raw('SUM(quantity) as total_sold')
        )
            ->with('product')
            ->groupBy('product_id')
            ->orderBy($sortBy, $sort)
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $topSelling->map(function ($item) {
                return [
                    'total_sold' => (int) $item->total_sold,
                    'product' => $item->product,
                ];
            }),
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'sortBy' => $sortBy,
            ],
            'count' => $topSelling->count(),
        ]);
    }



}
