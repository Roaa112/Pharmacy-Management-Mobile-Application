<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Brand\BrandController;
use App\Modules\Orders\OrderController;
use App\Modules\Points\PointController;
use App\Http\Controllers\HomeController;
use App\Modules\Banner\BannerController;
use App\Modules\Coupon\CouponsController;
use App\Modules\Product\ProductController;
use App\Modules\Setting\SettingController;
use App\Modules\Category\CategoryController;
use App\Modules\Discount\DiscountController;
use App\Modules\FlashSale\FlashSaleController;
use App\Modules\OpeningAd\OpeningAdController;
use App\Modules\Orders\ReturnRequestController;
use App\Modules\HealthIssue\HealthIssueController;
use App\Modules\DeliveryPrice\DeliveryPriceController;
use  App\Modules\HealthService\HealthServiceController;
use App\Modules\ContactMessage\ContactMassageController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::get('/product/{id}', function ($id) {
    $deepLink = "elkawther://product/{$id}";
    return redirect($deepLink);
});

// Every dashboard route requires an authenticated admin.
// Note: ->middleware() must come before ->group(); chaining it after group() has no effect.
Route::middleware('auth:admin')->group(function () {

Route::get('/dashboard',[HomeController::class,'home']);
Route::prefix('dashboard/health-results')->group(function () {
    Route::get('/body-weight', [HealthServiceController::class, 'bodyWeight'])->name('admin.health.body_weight');
    Route::get('/blood-sugar', [HealthServiceController::class, 'bloodSugar'])->name('admin.health.blood_sugar');
    Route::get('/blood-pressure', [HealthServiceController::class, 'bloodPressure'])->name('admin.health.blood_pressure');
    Route::get('/ovulation', [HealthServiceController::class, 'ovulation'])->name('admin.health.ovulation');
    Route::get('/pregnancy', [HealthServiceController::class, 'pregnancy'])->name('admin.health.pregnancy');
    Route::get('/children', [HealthServiceController::class, 'children'])->name('admin.health.children');
});

Route::prefix('dashboard/categories')->name('dashboard.categories.')->group(function () {
    Route::get('/', [CategoryController::class, 'index'])->name('index');
    Route::get('/create', [CategoryController::class, 'create'])->name('create');
    Route::post('/', [CategoryController::class, 'store'])->name('store');
    Route::put('/{category}', [CategoryController::class, 'update'])->name('update');
    Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('destroy');
    Route::post('{category}/storeSubcategory', [CategoryController::class, 'storeSubcategory'])->name('storeSubcategory');
});


Route::prefix('dashboard')->name('dashboard.')->group(function () {
    Route::resource('banners', BannerController::class);
    Route::resource('brands', BrandController::class);
    Route::resource('health_issues', HealthIssueController::class);
    Route::resource('flash_sales', FlashSaleController::class);
    Route::resource('delivery_prices', DeliveryPriceController::class);
    Route::resource('coupons', CouponsController::class);
    Route::resource('discounts', DiscountController::class);
    Route::resource('products', ProductController::class);
    Route::resource('settings', SettingController::class);
    Route::get('/contacts', [ContactMassageController::class, 'index']);
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::put('orders/{order}/update-status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');

    Route::get('returns', [ReturnRequestController::class, 'adminindex'])->name('returns.index');
    Route::get('returns/{id}', [ReturnRequestController::class, 'adminshow'])->name('returns.show');
    Route::post('returns/{id}/accept', [ReturnRequestController::class, 'accept'])->name('returns.accept');
    Route::post('returns/{id}/reject', [ReturnRequestController::class, 'reject'])->name('returns.reject');
    Route::resource('OpeningAd', OpeningAdController::class)->names('OpeningAd');

    Route::delete('/contact-messages/{id}', [ContactMassageController::class, 'destroy'])
    ->name('contact-messages.destroy');
    Route::get('points', [PointController::class, 'index'])->name('points.index');
Route::patch('/points/{order}/redeem', [PointController::class, 'redeemPoints'])
    ->name('points.redeem');
    Route::patch('/dashboard/points/redeem-multiple', [PointController::class, 'redeemMultiple'])
    ->name('points.redeemMultiple');

});

});




// The dashboard is admin-only; public self-registration of web accounts is not part of it.
Auth::routes(['register' => false]);

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
