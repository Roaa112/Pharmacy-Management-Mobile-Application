<?php

namespace App\Modules\Category;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Product\Services\ProductService;
use App\Modules\Category\Services\CategoryService;
use App\Models\OrderItem;

use App\Models\Product;

use Illuminate\Support\Facades\DB;

use App\Modules\Category\Requests\ListAllcategoriesRequest;


class ApiCategoryController extends Controller
{
    public function __construct(private CategoryService $categoryService,private ProductService $productService)
    {
    }
public function bestSellingProducts(Request $request, $categoryId)
{
    // التحقق من وجود الكاتيجوري
    $category = Category::find($categoryId);
    if (!$category) {
        return response()->json([
            'status' => false,
            'message' => 'Category not found',
        ], 404);
    }

    // جلب الـ IDs الخاصة بالكاتيجوري والساب كاتيجوريز
    $categoryIds = $this->getAllCategoryAndSubIds($category); // تأكد أن هذه الدالة موجودة ومُعرفة

    // المنتجات الأعلى مبيعًا حسب كمية الطلبات
    $bestProducts = OrderItem::select('product_id', DB::raw('SUM(quantity) as total_sold'))
        ->whereIn('product_id', function ($query) use ($categoryIds) {
            $query->select('id')
                  ->from('products')
                  ->whereIn('category_id', $categoryIds);
        })
        ->groupBy('product_id')
        ->orderByDesc('total_sold')
        ->get();

    // ترتيب الـ product_ids حسب كمية البيع
    $productIdsOrdered = $bestProducts->pluck('product_id')->toArray();

    // جلب بيانات المنتجات المرتبطة بهذه الـ IDs
    $products = Product::whereIn('id', $productIdsOrdered)
        ->with(['category', 'brand', 'productImages', 'sizes']) // جلب العلاقات المهمة
        ->get();

    // تنسيق البيانات حسب ترتيب الـ product_ids
    $productsData = collect($productIdsOrdered)->map(function ($productId) use ($products, $bestProducts) {
        $product = $products->firstWhere('id', $productId);

        if (!$product) return null;

        $sold = $bestProducts->firstWhere('product_id', $productId)?->total_sold ?? 0;

        // نحول المنتج لمصفوفة كاملة باستخدام toArray ونضيف عليها total_sold
        $productArray = $product->toArray();
        $productArray['total_sold'] = (int) $sold;

        return $productArray;
    })->filter()->values(); // حذف nulls وضبط الفهرسة

    return response()->json([
        'status' => true,
        'message' => 'Best selling products fetched successfully',
        'data' => $productsData,
    ]);
}

public function newArrivalProducts(Request $request, $categoryId)
{
    // تحقق من وجود الكاتيجري
    $category = Category::find($categoryId);
    if (!$category) {
        return response()->json([
            'status' => false,
            'message' => 'Category not found',
        ], 404);
    }

    // جلب IDs للفئة والفئات الفرعية
    $categoryIds = $this->getAllCategoryAndSubIds($category);

    // جلب المنتجات المرتبة من الأحدث
    $products = Product::whereIn('category_id', $categoryIds)
        ->with(['category', 'brand', 'sizes', 'productImages']) // علاقات مفيدة
        ->orderByDesc('created_at')
        ->get();

    // عرض كل بيانات المنتج
    $productsData = $products->map(function ($product) {
        $productArray = $product->toArray();
        $productArray['created_at'] = $product->created_at->toDateTimeString();
        return $productArray;
    });

    return response()->json([
        'status' => true,
        'message' => 'New arrival products fetched successfully',
        'data' => $productsData,
    ]);
}

// الة مساعدة لاستراع IDs الفئة + الفئت الفرعية
private function getAllCategoryAndSubIds(Category $category)
{
    // نجم id الفئة الحالية
    $ids = collect([$category->id]);

    // نجمع IDs للفات الفرعية (مباشرة)
    $children = $category->children; // يعتمد لو children يعيد فقط الدرج الأولى
    foreach ($children as $child) {
        $ids = $ids->merge($this->getAllCategoryAndSubIds($child));
    }

    return $ids->unique()->toArray();
}
    public function listAllCategories(ListAllcategoriesRequest $request)
    {
        $categories = $this->categoryService->listAllCategories($request->all());
        return successJsonResponse(
            data_get($categories, 'data'),
            __('categories.success.get_all_brands'),
            data_get($categories, 'count')
        );
    }

public function listAllCategoriesProducts($id)
{
    $limit = request()->get('limit', 10);
    $offset = request()->get('offset', 0);

    $category = Category::find($id);

    if (!$category) {
        return errorJsonResponse(__('Categories.errors.not_found'), 404);
    }

    $products = $category->products()
        ->with(['saleable', 'category']) // load relations
        ->offset($offset)
        ->limit($limit)
        ->get();

    $products = $products->map(function ($product) {
        $productArray = $product->toArray();
        $productArray['category_name'] = $product->category->name ?? null;
        return $productArray;
    });

    return successJsonResponse(
        $products,
        __('Categories.success.get_category_with_products_and_children'),
        $products->count()
    );
}





    public function searchCategoryProducts(Request $request, $categoryId)
    {
        $queryCriteria = $request->all();
        $queryCriteria['filters']['category_id'] = [
            'operator' => '=',
            'value' => $categoryId
        ];

        // استدعء  الـ ProductService
        $products = $this->productService->searchProducts($queryCriteria);

        return successJsonResponse(
            data_get($products, 'data'),
            __('Categories.success.get_category_with_filtered_products'),
            data_get($products, 'count')
        );
    }

}
