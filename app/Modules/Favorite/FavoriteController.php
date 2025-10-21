<?php
namespace App\Modules\Favorite;

use App\Models\Product;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    public function toggle(Request $request, $productId)
    {

         
        $user = $request->user();
    
        if (!$user) {
            Log::error('Unauthenticated user attempt');
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }
    
     
        $favorite = Favorite::where('user_id', $user->id)
                            ->where('product_id', $productId)
                            ->first();

        if ($favorite) {
            $favorite->delete();
            return response()->json(['message' => 'Removed from favorites']);
        } else {
            Favorite::create([
                'user_id' => $user->id,
                'product_id' => $productId,
            ]);
            return response()->json(['message' => 'Added to favorites']);
        }
    }



public function list()
{
    $user = Auth::user();

    // جلب المنتجات فقط من المفضلات مع العلاقات المطلوبة
    $favorites = $user->favorites()->with('product.category', 'product.productImages', 'product.saleable')->get();

    // استخراج المنتجات فقط
    $products = $favorites->pluck('product')->filter(); // filter() لضمان عدم وجود null

    return response()->json([
        'status' => 'success',
        'message' => 'Favorites.success.list',
        'data' => $products->values(), // إعادة فهرسة القيم
        'count' => $products->count(),
    ]);
}


}
