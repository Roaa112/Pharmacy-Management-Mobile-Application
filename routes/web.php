<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Brand\BrandController;
use App\Modules\Orders\OrderController;
use App\Modules\Banner\BannerController;
use App\Modules\Coupon\CouponsController;
use App\Modules\Contact\ContactController;
use App\Modules\Product\ProductController;
use App\Modules\Setting\SettingController;
use App\Modules\Category\CategoryController;
use App\Modules\Discount\DiscountController;
use App\Modules\FlashSale\FlashSaleController;
use App\Modules\OpeningAd\OpeningAdController;
use App\Modules\Orders\ReturnRequestController;
use App\Modules\HealthIssue\HealthIssueController;
use App\Modules\DeliveryPrice\DeliveryPriceController;
use App\Modules\ContactMessage\ContactMassageController;


Route::get('/', function () {
    return redirect('/dashboard');
});


Route::get('/dashboard', function () {
    return view('dashboard.index');
})->middleware('auth:admin');

Route::prefix('dashboard/categories')->name('dashboard.categories.')->group(function () {
    Route::get('/', [CategoryController::class, 'index'])->name('index');
    Route::get('/create', [CategoryController::class, 'create'])->name('create');
    Route::post('/', [CategoryController::class, 'store'])->name('store');
    Route::put('/{category}', [CategoryController::class, 'update'])->name('update');
    Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('destroy');
    Route::post('{category}/storeSubcategory', [CategoryController::class, 'storeSubcategory'])->name('storeSubcategory');
})->middleware('auth:admin');


Route::prefix('dashboard')->name('dashboard.')->group(function () {
    Route::resource('banners', BannerController::class)->middleware('auth:admin');
    Route::resource('brands', BrandController::class)->middleware('auth:admin');
    Route::resource('health_issues', HealthIssueController::class)->middleware('auth:admin');
    Route::resource('flash_sales', FlashSaleController::class)->middleware('auth:admin');
    Route::resource('delivery_prices', DeliveryPriceController::class)->middleware('auth:admin');
    Route::resource('coupons', CouponsController::class);
    Route::resource('discounts', DiscountController::class);
    Route::resource('products', ProductController::class);
    Route::resource('settings', SettingController::class);
    Route::get('/contacts', [ContactMassageController::class, 'index'])->middleware('auth:admin');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index')->middleware('auth:admin');
    Route::put('orders/{order}/update-status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');

    Route::get('returns', [ReturnRequestController::class, 'adminindex'])->name('returns.index');
    Route::get('returns/{id}', [ReturnRequestController::class, 'adminshow'])->name('returns.show');
    Route::post('returns/{id}/accept', [ReturnRequestController::class, 'accept'])->name('returns.accept');
    Route::post('returns/{id}/reject', [ReturnRequestController::class, 'reject'])->name('returns.reject');
    Route::resource('OpeningAd', OpeningAdController::class)->names('OpeningAd');

    Route::delete('/contact-messages/{id}', [ContactMassageController::class, 'destroy'])
    ->name('contact-messages.destroy')
    ->middleware('auth:admin');

})->middleware('auth:admin');





Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
