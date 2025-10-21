<?php
namespace App\Modules\Rate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\Request;

class ProductReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $user = $request->user();
        if (!$user) {
            Log::error('Unauthenticated user attempt');
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'rate' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        $review = ProductReview::updateOrCreate(
            [
                'product_id' => $product->id,
                'user_id' => $user->id,
            ],
            [
                'rate' => $validated['rate'],
                'review' => $validated['review'],
            ]
        );

        // تحديث متوسط التقييم في المنتج (اختياري)
        $product->rate = $product->reviews()->avg('rate');
        $product->save();

        return response()->json(['message' => 'تم حفظ التقييم والمراجعة بنجاح.', 'data' => $review]);
    }

    public function index(Product $product)
    {
        $reviews = $product->reviews()
            ->with('user:id,name') // إرجاع اسم المستخدم
            ->latest()
            ->get(['id', 'user_id', 'rate', 'review', 'created_at']);

        return response()->json(['data' => $reviews]);
    }
}
