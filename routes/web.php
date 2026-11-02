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
use App\Modules\Discount\CampaignController;
use App\Modules\Discount\MarketingDashboardController;
use App\Modules\FlashSale\FlashSaleController;
use App\Modules\OpeningAd\OpeningAdController;
use App\Modules\Orders\ReturnRequestController;
use App\Modules\HealthIssue\HealthIssueController;
use App\Modules\DeliveryPrice\DeliveryPriceController;
use  App\Modules\HealthService\HealthServiceController;
use App\Modules\ContactMessage\ContactMassageController;
use App\Modules\Customers\CustomerController;
use App\Modules\Inventory\InventoryController;
use App\Modules\Reports\ReportController;
use App\Modules\Admin\AdminUserController;
use App\Modules\Admin\AuditLogController;
use App\Modules\Admin\PrivateFileController;
use App\Modules\Delivery\Admin\LocationAdminController;
use App\Modules\Delivery\Admin\DeliveryZoneController;
use App\Modules\Delivery\Admin\DeliveryProviderController;
use App\Modules\Delivery\Admin\ShipmentController;
use App\Modules\Payments\PaymentMethodController;
use App\Modules\Payments\Admin\PaymentController;
use App\Modules\Payments\Admin\RefundController;
use App\Modules\Payments\Admin\CodController;
use App\Modules\Inventory\WarehouseController;
use App\Modules\Inventory\TransferController;
use App\Modules\Inventory\StockCountController;
use App\Modules\Inventory\SupplierController;
use App\Modules\Inventory\PurchaseOrderController;
use App\Modules\HealthService\Admin\VaccineScheduleController;
use App\Modules\HealthService\Admin\PregnancyContentController;
use App\Modules\HealthService\Admin\NotificationLogController;

Route::get('/', function () {
    return redirect('/dashboard');
});

// The customer's browser returning from the payment page (signed by the provider; see PaymentWebhookController).
Route::get('/payments/{provider}/return', [\App\Modules\Payments\Api\PaymentWebhookController::class, 'return'])
    ->whereIn('provider', ['paymob'])->middleware('throttle:60,1')->name('payments.return');

// Private uploads through expiring signed links (see SignedFileController).
Route::get('/files/{type}/{id}', [\App\Modules\Admin\SignedFileController::class, 'show'])
    ->whereIn('type', ['payment-receipt', 'return-image', 'medication'])->whereNumber('id')
    ->middleware(['signed', 'throttle:120,1'])->name('files.signed');

Route::get('/product/{id}', function ($id) {
    $deepLink = "elkawther://product/{$id}";
    return redirect($deepLink);
});

// Every dashboard route requires an authenticated, active admin; each section also requires its
// permission (App\Enums\Permission, granted by the admin's role).
// Note: ->middleware() must come before ->group(); chaining it after group() has no effect.
Route::middleware(['auth:admin', \App\Http\Middleware\EnsureAccountIsActive::class])->group(function () {

Route::get('/dashboard',[HomeController::class,'home'])->name('dashboard.home');

Route::prefix('dashboard')->name('dashboard.')->group(function () {
    Route::middleware('can:users')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::patch('customers/{customer}/active', [CustomerController::class, 'toggleActive'])->name('customers.toggleActive');
        Route::get('/contacts', [ContactMassageController::class, 'index'])->name('contacts.index');
        Route::delete('/contact-messages/{id}', [ContactMassageController::class, 'destroy'])->name('contact-messages.destroy');
    });

    // Stock: read and change. Every change is a ledger movement with a reason and is audited.
    Route::middleware('can:inventory')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::get('inventory/reports', [InventoryController::class, 'reports'])->name('inventory.reports');
        Route::get('inventory/skus/{size}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::put('inventory/skus/{size}', [InventoryController::class, 'updateSku'])->name('inventory.updateSku');
        Route::post('inventory/skus/{size}/operations', [InventoryController::class, 'operate'])->name('inventory.operate');

        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation'])->name('warehouses.locations.store');
        Route::put('warehouse-locations/{location}', [WarehouseController::class, 'updateLocation'])->name('warehouses.locations.update');

        Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer'])->name('transfers.dispatch');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/close', [TransferController::class, 'close'])->name('transfers.close');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

        Route::get('stock-counts', [StockCountController::class, 'index'])->name('counts.index');
        Route::post('stock-counts', [StockCountController::class, 'store'])->name('counts.store');
        Route::get('stock-counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
        Route::post('stock-counts/{count}/record', [StockCountController::class, 'record'])->name('counts.record');
        Route::post('stock-counts/{count}/complete', [StockCountController::class, 'complete'])->name('counts.complete');
        Route::post('stock-counts/{count}/cancel', [StockCountController::class, 'cancel'])->name('counts.cancel');
    });

    Route::middleware('can:purchasing')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::post('suppliers/{supplier}/invoices', [SupplierController::class, 'storeInvoice'])->name('suppliers.invoices.store');
        Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'storePayment'])->name('suppliers.payments.store');
        Route::post('suppliers/{supplier}/returns', [SupplierController::class, 'storeReturn'])->name('suppliers.returns.store');
        Route::post('supplier-returns/{return}/dispatch', [SupplierController::class, 'dispatchReturn'])->name('supplier_returns.dispatch');
        Route::post('supplier-returns/{return}/cancel', [SupplierController::class, 'cancelReturn'])->name('supplier_returns.cancel');

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase_orders.index');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase_orders.store');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase_orders.show');
        Route::post('purchase-orders/{purchaseOrder}/order', [PurchaseOrderController::class, 'markOrdered'])->name('purchase_orders.order');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase_orders.receive');
        Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase_orders.close');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase_orders.cancel');
    });

    Route::middleware('can:catalog')->group(function () {
        Route::resource('brands', BrandController::class);
        Route::resource('health_issues', HealthIssueController::class);
        Route::resource('products', ProductController::class);
    });

    Route::get('reports', [ReportController::class, 'index'])->middleware('can:reports')->name('reports.index');

    Route::middleware('can:orders')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::put('orders/{order}/update-status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    });
    Route::patch('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->middleware('can:payments')->name('orders.paymentStatus');
    Route::get('files/payment-receipt/{order}', [PrivateFileController::class, 'paymentReceipt'])->middleware('can:payments')->name('files.paymentReceipt');

    Route::middleware('can:refunds')->group(function () {
        Route::get('returns', [ReturnRequestController::class, 'adminindex'])->name('returns.index');
        Route::get('returns/{id}', [ReturnRequestController::class, 'adminshow'])->name('returns.show');
        Route::post('returns/{id}/accept', [ReturnRequestController::class, 'accept'])->name('returns.accept');
        Route::post('returns/{id}/reject', [ReturnRequestController::class, 'reject'])->name('returns.reject');
        Route::get('files/return-image/{image}', [PrivateFileController::class, 'returnImage'])->name('files.returnImage');
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
        Route::post('payments/{payment}/refunds', [RefundController::class, 'store'])->name('refunds.store');
        Route::post('refunds/{refund}/process', [RefundController::class, 'process'])->name('refunds.process');
        Route::post('refunds/{refund}/reconcile', [RefundController::class, 'reconcile'])->name('refunds.reconcile');
        Route::post('refunds/{refund}/cancel', [RefundController::class, 'cancel'])->name('refunds.cancel');
    });

    Route::middleware('can:loyalty')->group(function () {
        Route::get('points', [PointController::class, 'index'])->name('points.index');
        Route::patch('/points/{order}/redeem', [PointController::class, 'redeemPoints'])->name('points.redeem');
        Route::patch('/dashboard/points/redeem-multiple', [PointController::class, 'redeemMultiple'])->name('points.redeemMultiple');
    });

    Route::middleware('can:marketing')->group(function () {
        Route::get('marketing', [MarketingDashboardController::class, 'index'])->name('marketing.index');
        Route::resource('banners', BannerController::class);
        Route::post('flash_sales/{flash_sale}/toggle', [FlashSaleController::class, 'toggle'])->name('flash_sales.toggle');
        Route::resource('flash_sales', FlashSaleController::class);
        Route::post('coupons/{coupon}/toggle', [CouponsController::class, 'toggle'])->name('coupons.toggle');
        Route::resource('coupons', CouponsController::class);
        // Promotions (the table and route names keep their historical name, discounts).
        Route::post('discounts/{discount}/toggle', [DiscountController::class, 'toggle'])->name('discounts.toggle');
        Route::post('discounts/{discount}/duplicate', [DiscountController::class, 'duplicate'])->name('discounts.duplicate');
        Route::post('discounts/{discount}/simulate', [DiscountController::class, 'simulate'])->name('discounts.simulate');
        Route::resource('discounts', DiscountController::class);
        Route::resource('campaigns', CampaignController::class)->except('show');
        Route::resource('OpeningAd', OpeningAdController::class)->names('OpeningAd');
    });

    Route::middleware('can:delivery')->group(function () {
        // The old delivery prices screen now lives under delivery zones.
        Route::get('delivery_prices', fn () => redirect()->route('dashboard.delivery.zones.index'))->name('delivery_prices.index');
        Route::get('cities', [LocationAdminController::class, 'index'])->name('cities.index');
        Route::post('cities', [LocationAdminController::class, 'storeCity'])->name('cities.store');
        Route::put('cities/{city}', [LocationAdminController::class, 'updateCity'])->name('cities.update');
        Route::post('cities/{city}/areas', [LocationAdminController::class, 'storeArea'])->name('areas.store');
        Route::put('areas/{area}', [LocationAdminController::class, 'updateArea'])->name('areas.update');

        Route::prefix('delivery')->name('delivery.')->group(function () {
            Route::get('zones', [DeliveryZoneController::class, 'index'])->name('zones.index');
            Route::post('zones', [DeliveryZoneController::class, 'store'])->name('zones.store');
            Route::put('zones/{zone}', [DeliveryZoneController::class, 'update'])->name('zones.update');
            Route::delete('zones/{zone}', [DeliveryZoneController::class, 'destroy'])->name('zones.destroy');
            Route::post('zones/{zone}/rates', [DeliveryZoneController::class, 'storeRate'])->name('rates.store');
            Route::put('rates/{rate}', [DeliveryZoneController::class, 'updateRate'])->name('rates.update');
            Route::delete('rates/{rate}', [DeliveryZoneController::class, 'destroyRate'])->name('rates.destroy');

            Route::get('providers', [DeliveryProviderController::class, 'index'])->name('providers.index');
            Route::post('providers', [DeliveryProviderController::class, 'store'])->name('providers.store');
            Route::put('providers/{provider}', [DeliveryProviderController::class, 'update'])->name('providers.update');
            Route::post('providers/{provider}/status-maps', [DeliveryProviderController::class, 'storeStatusMap'])->name('statusMaps.store');
            Route::delete('status-maps/{map}', [DeliveryProviderController::class, 'destroyStatusMap'])->name('statusMaps.destroy');

            Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
            Route::post('shipments/{shipment}/assign', [ShipmentController::class, 'assign'])->name('shipments.assign');
            Route::post('shipments/{shipment}/events', [ShipmentController::class, 'addEvent'])->name('shipments.events');
            Route::post('shipments/{shipment}/settle-cod', [ShipmentController::class, 'settleCod'])->name('shipments.settleCod');
            Route::post('orders/{order}/shipments', [ShipmentController::class, 'createForOrder'])->name('shipments.create');
        });
    });

    Route::middleware('can:payments')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/events', [PaymentController::class, 'events'])->name('payments.events');
        Route::get('payments/reports', [PaymentController::class, 'reports'])->name('payments.reports');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
        Route::post('payments/{payment}/collect', [PaymentController::class, 'collect'])->name('payments.collect');
        Route::post('payments/{payment}/refresh', [PaymentController::class, 'refresh'])->name('payments.refresh');
        Route::get('cod', [CodController::class, 'index'])->name('cod.index');
        Route::post('cod/settlements', [CodController::class, 'settle'])->name('cod.settle');
        Route::get('cod/settlements/{settlement}', [CodController::class, 'show'])->name('cod.settlements.show');
        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment_methods.index');
        Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment_methods.store');
        Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('payment_methods.update');
    });

    Route::middleware('can:settings')->group(function () {
        // A single settings row: no create or delete.
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingController::class, 'store'])->name('settings.store');
        Route::put('settings/{setting}', [SettingController::class, 'update'])->name('settings.update');
    });

    Route::middleware('can:health_content')->prefix('health-content')->name('health_content.')->group(function () {
        Route::get('vaccines', [VaccineScheduleController::class, 'index'])->name('vaccines.index');
        Route::post('vaccines', [VaccineScheduleController::class, 'store'])->name('vaccines.store');
        Route::put('vaccines/{item}', [VaccineScheduleController::class, 'update'])->name('vaccines.update');
        Route::delete('vaccines/{item}', [VaccineScheduleController::class, 'destroy'])->name('vaccines.destroy');
        Route::get('pregnancy', [PregnancyContentController::class, 'index'])->name('pregnancy.index');
        Route::post('pregnancy', [PregnancyContentController::class, 'store'])->name('pregnancy.store');
        Route::put('pregnancy/{content}', [PregnancyContentController::class, 'update'])->name('pregnancy.update');
        Route::delete('pregnancy/{content}', [PregnancyContentController::class, 'destroy'])->name('pregnancy.destroy');
    });

    Route::middleware('can:admins')->group(function () {
        Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
        Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
        Route::put('admins/{admin}', [AdminUserController::class, 'update'])->name('admins.update');
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');
    });
});

Route::prefix('dashboard/categories')->name('dashboard.categories.')->middleware('can:catalog')->group(function () {
    Route::get('/', [CategoryController::class, 'index'])->name('index');
    Route::get('/create', [CategoryController::class, 'create'])->name('create');
    Route::post('/', [CategoryController::class, 'store'])->name('store');
    Route::put('/{category}', [CategoryController::class, 'update'])->name('update');
    Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('destroy');
    Route::post('{category}/storeSubcategory', [CategoryController::class, 'storeSubcategory'])->name('storeSubcategory');
});

// Customers' health records: only admins with the health_data permission; every view is audited.
Route::prefix('dashboard/health-results')->middleware('can:health_data')->group(function () {
    Route::get('/body-weight', [HealthServiceController::class, 'bodyWeight'])->name('admin.health.body_weight');
    Route::get('/blood-sugar', [HealthServiceController::class, 'bloodSugar'])->name('admin.health.blood_sugar');
    Route::get('/blood-pressure', [HealthServiceController::class, 'bloodPressure'])->name('admin.health.blood_pressure');
    Route::get('/ovulation', [HealthServiceController::class, 'ovulation'])->name('admin.health.ovulation');
    Route::get('/pregnancy', [HealthServiceController::class, 'pregnancy'])->name('admin.health.pregnancy');
    Route::get('/children', [HealthServiceController::class, 'children'])->name('admin.health.children');
    Route::get('/notifications', [NotificationLogController::class, 'index'])->name('admin.health.notifications');
});

});




// The dashboard is admin-only; public self-registration of web accounts is not part of it.
Auth::routes(['register' => false, 'reset' => false]);

// Laravel UI redirects here after login; the admin home is the dashboard.
Route::redirect('/home', '/dashboard')->name('home');
