<?php
namespace App\Modules\HealthIssue;

use App\Models\HealthIssue;
use Illuminate\Http\Request;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;
use App\Modules\HealthIssue\Services\HealthIssueService;
use App\Modules\HealthIssue\Requests\ListAllHealthIssuesRequest;


class ApiHealthIssueController extends Controller
{
    public function __construct(private HealthIssueService $healthIssueService)
    {
    }
  
    public function bestSellingProductsByHealthIssue(Request $request, $healthIssueId)
{
    // تحقق من وجود المشكلة الصحية
    $healthIssue = HealthIssue::find($healthIssueId);
    if (!$healthIssue) {
        return response()->json([
            'status' => false,
            'message' => 'Health issue not found',
        ], 404);
    }

    // جلب IDs المنتجات المرتبطة بالمشكلة الصحية
    $productIds = $healthIssue->products()->pluck('products.id')->toArray();

    if (empty($productIds)) {
        return response()->json([
            'status' => true,
            'message' => 'No products found for this health issue',
            'data' => [],
        ]);
    }

    // المنتجات الأعلى مبيعًا حسب كمية الطلبات
    $bestProducts = OrderItem::select('product_id', DB::raw('SUM(quantity) as total_sold'))
        ->whereIn('product_id', $productIds)
        ->groupBy('product_id')
        ->orderByDesc('total_sold')
        ->get();

    // ترتيب الـ product_ids حسب كمية البيع
    $productIdsOrdered = $bestProducts->pluck('product_id')->toArray();

    // جلب المنتجات
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
        'message' => 'Best selling products for this health issue fetched successfully',
        'data' => $productsData,
    ]);
}
public function newArrivalProductsByHealthIssue(Request $request, $healthIssueId)
{
    // تحقق من وجود المشكلة الصحية
    $healthIssue = HealthIssue::find($healthIssueId);
    if (!$healthIssue) {
        return response()->json([
            'status' => false,
            'message' => 'Health issue not found',
        ], 404);
    }

    // جلب المنتجات المرتبطة بها
    $products = $healthIssue->products()
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
        'message' => 'New arrival products for this health issue fetched successfully',
        'data' => $productsData,
    ]);
}
    public function listAllHealthIssues(ListAllHealthIssuesRequest $request)
    {
        $healthIssues = $this->healthIssueService->listAllHealthIssues($request->all());
        return successJsonResponse(
            data_get($healthIssues, 'data'),
            __('healthIssues.success.get_all_brands'),
            data_get($healthIssues, 'count')
        );
    }
 public function listAllHealthIssuesProducts($id)
{
    $limit = request()->get('limit', 10); // default 10
    $offset = request()->get('offset', 0);

    $healthIssue = HealthIssue::find($id);

    if (!$healthIssue) {
        return errorJsonResponse(__('HealthIssues.errors.not_found'), 404);
    }

    // Load products with relations (no manual discount logic)
    $products = $healthIssue->products()
        ->with(['saleable', 'category', 'sizes']) // eager load
        ->offset($offset)
        ->limit($limit)
        ->get();

    // فقط بنضيف اسم التصنيف بدون أي حسابات
    $products = $products->map(function ($product) {
        $productArray = $product->toArray();
        $productArray['category_name'] = $product->category->name ?? null;
        return $productArray;
    });

    // نربط المنتجا بالـ health issue
    $healthIssue->setRelation('products', $products);

    return successJsonResponse(
        $healthIssue,
        __('HealthIssues.success.get_health_issue_with_products'),
        $products->count()
    );
}



}
