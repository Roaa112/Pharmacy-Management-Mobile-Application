<?php

namespace App\Modules\HealthIssue;

use App\Models\HealthIssue;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\HealthIssue\Services\HealthIssueService;
use App\Modules\HealthIssue\Requests\ListAllHealthIssuesRequest;


class ApiHealthIssueController extends Controller
{
    public function __construct(private HealthIssueService $healthIssueService)
    {
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

    // نربط المنتجات بالـ health issue
    $healthIssue->setRelation('products', $products);

    return successJsonResponse(
        $healthIssue,
        __('HealthIssues.success.get_health_issue_with_products'),
        $products->count()
    );
}



}
