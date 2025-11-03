<?php

use Illuminate\Http\Request;
use App\Modules\Cart\CartController;
use App\Modules\User\UserController;
use Illuminate\Support\Facades\Route;
use App\Modules\Orders\OrderController;
use App\Modules\Points\ApiPointsController;

use App\Modules\User\ApiUserController;
use App\Modules\DeliveryPrice\DeliveryPriceController;
use App\Modules\Brand\ApiBrandController;
use App\Modules\Banner\ApiBannerController;
use App\Modules\Favorite\FavoriteController;
use App\Modules\Location\LocationController;
use App\Modules\Address\ApiAddressController;
use App\Modules\Contact\ApiContactController;
use App\Modules\Developer\ProjectsController;
use App\Modules\Product\ApiProductController;
use App\Modules\Rate\ProductReviewController;
use App\Modules\Setting\ApiSettingController;
use App\Modules\Category\ApiCategoryController;
use App\Modules\Orders\ReturnRequestController;
use App\Modules\Medication\MedicationController;
use App\Modules\OpeningAd\ApiOpeningAdController;
use App\Modules\Developer\ProjectExportController;
use App\Modules\HealthIssue\ApiHealthIssueController;
use App\Modules\HealthService\HealthServiceController;
use App\Modules\ContactMessage\ApiContactMassageController;
use App\Modules\ContactMessage\ApiContactMessageController;



Route::middleware('auth:sanctum')->group(function () {
    Route::post('/user/orders', [OrderController::class, 'checkout']);
});


Route::middleware(['setLocaleLang'])->get('/test-json', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'This is a valid JSON response',
        'data' => [
            'current_locale' => app()->getLocale(),
            'is_arabic' => (app()->getLocale() === 'ar')
        ]
    ]);
});
 Route::middleware('auth:sanctum')->get('/token/check', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Token is valid',
        'user' => auth()->user()
    ]);
});
Route::middleware([\App\Http\Middleware\SetLocaleLang::class])
    ->prefix('v1')
    ->group(function () {
        Route::prefix('user')->group(function () {
    Route::get('/products/{product}/related', [ApiProductController::class, 'relatedProducts']);
   Route::get('brands',[ApiBrandController::class,'listAllBrands'] );
    Route::get('/brands/{id}/products', [ApiBrandController::class, 'listAllBrandsProducts']);
    Route::get('categories',[ApiCategoryController::class,'listAllCategories'] );
    Route::get('categories/{id}/products',[ApiCategoryController::class,'listAllCategoriesProducts'] );
    Route::get('/categories/{id}/products/search', [ApiCategoryController::class, 'searchCategoryProducts']);
    Route::get('settings',[ApiSettingController::class,'listAllsettings'] );
    Route::get('health_issues',[ApiHealthIssueController::class,'listAllHealthIssues'] );
    Route::get('health_issues/{id}/products',[ApiHealthIssueController::class,'listAllHealthIssuesProducts'] );
    Route::get('banners', [ApiBannerController::class, 'listAllBanners']);
    Route::get('products',[ApiProductController::class,'listAllProducts'] );
    Route::get('show/{id}/product',[ApiProductController::class,'showProduct'] );
    Route::get('products/on/sale',[ApiProductController::class,'listAllProductsOnSale'] );
    Route::get('/products/search', [ApiProductController::class, 'searchProducts']);
    Route::get('latest-products', [ApiProductController::class, 'latestProducts']);
    Route::get('top-discount-products', [ApiProductController::class, 'topDiscountProducts']);
    Route::get('openingAd', [ApiOpeningAdController::class, 'listAllOpeningAds']);
 Route::get('categories/{category}/best-selling-products', [ApiCategoryController::class, 'bestSellingProducts']);
          Route::get('categories/{category_id}/new-arrival-products', [ApiCategoryController::class, 'newArrivalProducts']);


                 Route::get('healthissues/{id}/best-selling-products', [ApiHealthIssueController::class, 'bestSellingProductsByHealthIssue']);
          Route::get('healthissues/{id}/new-arrival-products', [ApiHealthIssueController::class, 'newArrivalProductsByHealthIssue']);
            Route::get('brands/{id}/best-selling-products', [ApiBrandController::class, 'bestSellingProductsByBrand']);
          Route::get('brands/{id}/new-arrival-products', [ApiBrandController::class, 'newArrivalProductsByBrand']);
    //fake
 Route::get('Best-selling-products', [ApiProductController::class, 'productSell']);

           Route::get('delivary_prices', [DeliveryPriceController::class, 'indexapi']);
        });  // <-- هذه مغل بشكل صحيح
});
Route::prefix('user')->group(function () {
    Route::post('register', [UserController::class, 'createUser']);

    Route::post('verify', [UserController::class, 'verify']);
    Route::post('login', [UserController::class, 'login']);


    Route::post('password/forgot', [UserController::class, 'sendResetOtp']);
    Route::post('password/verify-otp', [UserController::class, 'verifyOtp']);
    Route::middleware('auth:sanctum')->post('password/reset', [UserController::class, 'resetPassword']);

});
  Route::post('password/reset/auth', [UserController::class, 'resetPasswordAuth']);
Route::middleware('auth:sanctum')->put('user/update/{userId}', [UserController::class, 'updateUser']);
Route::middleware('auth:sanctum')->post('logout', [UserController::class, 'logout']);
Route::middleware(['auth:sanctum'])->middleware(['auth:sanctum', \App\Http\Middleware\SetLocaleLang::class])->get('user/favorites', [FavoriteController::class, 'list']);
Route::middleware(['auth:sanctum'])->post('user/favorites/{productId}', [FavoriteController::class, 'toggle']);
Route::middleware(['auth:sanctum'])->post('user/products/{product}/rate', [ProductReviewController::class, 'store']);
Route::get('user/products/{product}/reviews', [ProductReviewController::class, 'index']);

Route::middleware(['auth:sanctum'])->get('user/track-order/{id}', [OrderController::class, 'track']);

Route::middleware(['auth:sanctum'])->get('user/order-details/{id}', [OrderController::class, 'details']);
Route::middleware(['auth:sanctum'])->get('user/orders/grouped-by-status', [OrderController::class, 'groupedByStatus']);

Route::middleware(['auth:sanctum'])->get('user/points', [OrderController::class, 'points']);



Route::middleware(['auth:sanctum', \App\Http\Middleware\SetLocaleLang::class])
    ->prefix('user')
    ->group(function () {

    Route::get('user/data',[ApiUserController::class,'getUserData'] );

    Route::get('addresses', [ApiAddressController::class, 'listAllAddresses'])->name('user.addresses.index');
    Route::get('addresses/{id}', [ApiAddressController::class, 'getAddressById'])->name('user.addresses.show');
    Route::post('addresses', [ApiAddressController::class, 'createAddress'])->name('user.addresses.store');
    Route::put('addresses/{id}', [ApiAddressController::class, 'updateAddress'])->name('user.addresses.update');
    Route::delete('addresses/{id}', [ApiAddressController::class, 'deleteAddress'])->name('user.addresses.destroy');

    Route::post('/addresses/{id}/toggle-default', [ApiAddressController::class, 'toggleDefault']);
    Route::get('/medication', [MedicationController::class, 'index']);
    Route::post('/medication', [MedicationController::class, 'store']);
    Route::put('{medication}', [MedicationController::class, 'update']);
    Route::delete('/medication/{medication}', [MedicationController::class, 'destroy']);
    Route::post('/medications/{medication}/log', [MedicationController::class, 'updateLogStatus']);
    Route::delete('/medications/remove-day', [MedicationController::class, 'deleteDayFromMedication']);
    Route::post('/medications/{medication}/update', [MedicationController::class, 'update']);


Route::post('/returns', [ReturnRequestController::class, 'store']);
Route::get('/returns', [ReturnRequestController::class, 'index']);
Route::get('/returns/{id}', [ReturnRequestController::class, 'show']);
Route::post('/returns/{id}/cancel', [ReturnRequestController::class, 'cancel']);








});

Route::middleware('auth:sanctum')->prefix('user/health')->group(function () {

 Route::get('/blood-pressure', [HealthServiceController::class, 'getBloodPressure']);
    Route::get('/blood-sugar', [HealthServiceController::class, 'getBloodSugar']);
    Route::get('/weight', [HealthServiceController::class, 'getWeight']);


    Route::post('/body-weight', [HealthServiceController::class, 'storeBodyWeight']);

    Route::post('/ovulation', [HealthServiceController::class, 'storeOvulation']);
    Route::get('/ovulation/current', [HealthServiceController::class, 'getCycleForCurrentMonth']);


    Route::post('/blood-sugar', [HealthServiceController::class, 'storeBloodSugar']);

    Route::post('/blood-pressure', [HealthServiceController::class, 'storeBloodPressure']);

    Route::post('/calculatePregnancy', [HealthServiceController::class, 'storePregnancy']);

    Route::post('/child', [HealthServiceController::class, 'storeVaccination']);

    Route::delete('/child/{id}', [HealthServiceController::class, 'destroychild']);
    Route::get('/CurrentPregnancyStage', [HealthServiceController::class, 'getCurrentPregnancyStage']);


    Route::get('/child/vaccines', [HealthServiceController::class, 'getVaccines']);

    Route::patch('/vaccines/{vaccine}/complete', [HealthServiceController::class, 'markAsCompleted']);

    // Route::post('ovulation', [HealthServiceController::class, 'storeOvulation']);
    // Route::post('body-weight', [HealthServiceController::class, 'storeBodyWeight']);
    // Route::post('blood-sugar', [HealthServiceController::class, 'storeBloodSugar']);
    // Route::post('blood-pressure', [HealthServiceController::class, 'storeBloodPressure']);
    // Route::post('pregnancy', [HealthServiceController::class, 'storePregnancy']);
    // Route::post('vaccination', [HealthServiceController::class, 'storeVaccination']);



    // Route::get('/ovulation', [HealthServiceController::class, 'getOvulationResults']);
    // Route::get('/body-weight', [HealthServiceController::class, 'getBodyWeightResults']);
    // Route::get('/blood-sugar', [HealthServiceController::class, 'getBloodSugarResults']);
    // Route::get('/blood-pressure', [HealthServiceController::class, 'getBloodPressureResults']);
    // Route::get('/pregnancy', [HealthServiceController::class, 'getPregnancyCalculations']);
    // Route::get('/vaccination', [HealthServiceController::class, 'getVaccinationSchedules']);

});


Route::middleware('auth:sanctum')->post('user/contact-messages', [ApiContactMassageController::class, 'store']);
Route::middleware('auth:sanctum')->prefix('user/cart')->group(function () {

    Route::get('/', [CartController::class, 'showCart']); // get cart items
    Route::post('/', [CartController::class, 'addToCart']); // add or update item to cart
    Route::delete('/{id}', [CartController::class, 'removeItem']);
    Route::post('/apply-coupon', [CartController::class, 'applyCoupon']); // apply coupon
    Route::post('/update-quantity', [CartController::class, 'updateQuantity']);
});


Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('points/valid', [ApiPointsController::class, 'getValidPoints']);
    Route::get('points/expired', [ApiPointsController::class, 'getExpiredPoints']);
    Route::get('points/notvalid', [ApiPointsController::class, 'getConsumedPoints']);
});
