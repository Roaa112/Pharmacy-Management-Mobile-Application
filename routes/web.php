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
use App\Modules\Admin\RoleController;
use App\Modules\Points\LoyaltyDashboardController;
use App\Modules\Points\LoyaltySettingsController;
use App\Modules\Points\RewardController;
use App\Modules\Points\RedemptionController;
use App\Modules\DataTransfer\DataTransferController;

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

// Every dashboard route requires an authenticated, active admin and the permission of what it does
// (database roles, see PermissionRegistry). Reading a section needs its ".view" permission; every
// change needs its own permission. Hiding a button is never the protection.
// Note: ->middleware() must come before ->group(); chaining it after group() has no effect.
Route::middleware(['auth:admin', \App\Http\Middleware\EnsureAccountIsActive::class])->group(function () {

Route::get('/dashboard',[HomeController::class,'home'])->name('dashboard.home');

/**
 * A resource controller split by permission: index/show need $view, create/store $create,
 * edit/update $update, destroy $delete.
 */
$resource = function (string $name, string $controller, string $view, string $create, string $update, string $delete, array $options = []) {
    $names = $options['names'] ?? null;
    $register = function (array $actions, string $permission) use ($name, $controller, $names) {
        $route = Route::resource($name, $controller)->only($actions)->middleware('can:'.$permission);
        if ($names) {
            $route->names($names);
        }
    };
    $except = $options['except'] ?? [];
    // create before show, or GET {name}/create would be read as show({name} = "create").
    $register(array_diff(['create', 'store'], $except), $create);
    $register(array_diff(['index', 'show'], $except), $view);
    $register(array_diff(['edit', 'update'], $except), $update);
    $register(array_diff(['destroy'], $except), $delete);
};

Route::prefix('dashboard')->name('dashboard.')->group(function () use ($resource) {
    Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
    Route::patch('customers/{customer}/active', [CustomerController::class, 'toggleActive'])->middleware('can:customers.activate')->name('customers.toggleActive');
    Route::get('/contacts', [ContactMassageController::class, 'index'])->middleware('can:contact_messages.view')->name('contacts.index');
    Route::delete('/contact-messages/{id}', [ContactMassageController::class, 'destroy'])->middleware('can:contact_messages.delete')->name('contact-messages.destroy');

    // Stock: read and change. Every change is a ledger movement with a reason and is audited.
    Route::middleware('can:inventory.view')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::get('inventory/reports', [InventoryController::class, 'reports'])->name('inventory.reports');
        Route::get('inventory/skus/{size}', [InventoryController::class, 'show'])->name('inventory.show');
        Route::put('inventory/skus/{size}', [InventoryController::class, 'updateSku'])->middleware('can:inventory.adjust')->name('inventory.updateSku');
        Route::post('inventory/skus/{size}/operations', [InventoryController::class, 'operate'])->middleware('can:inventory.adjust')->name('inventory.operate');

        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::middleware('can:warehouses.manage')->group(function () {
            Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
            Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation'])->name('warehouses.locations.store');
            Route::put('warehouse-locations/{location}', [WarehouseController::class, 'updateLocation'])->name('warehouses.locations.update');
        });

        Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::middleware('can:inventory.transfer')->group(function () {
            Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
            Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
            Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer'])->name('transfers.dispatch');
            Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
            Route::post('transfers/{transfer}/close', [TransferController::class, 'close'])->name('transfers.close');
            Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
        });

        Route::get('stock-counts', [StockCountController::class, 'index'])->name('counts.index');
        Route::get('stock-counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
        Route::middleware('can:inventory.count')->group(function () {
            Route::post('stock-counts', [StockCountController::class, 'store'])->name('counts.store');
            Route::post('stock-counts/{count}/record', [StockCountController::class, 'record'])->name('counts.record');
            Route::post('stock-counts/{count}/complete', [StockCountController::class, 'complete'])->name('counts.complete');
            Route::post('stock-counts/{count}/cancel', [StockCountController::class, 'cancel'])->name('counts.cancel');
        });
    });

    Route::middleware('can:purchasing.view')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.manage')->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.manage')->name('suppliers.update');
        Route::middleware('can:supplier_finance.manage')->group(function () {
            Route::post('suppliers/{supplier}/invoices', [SupplierController::class, 'storeInvoice'])->name('suppliers.invoices.store');
            Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'storePayment'])->name('suppliers.payments.store');
            Route::post('suppliers/{supplier}/returns', [SupplierController::class, 'storeReturn'])->name('suppliers.returns.store');
            Route::post('supplier-returns/{return}/dispatch', [SupplierController::class, 'dispatchReturn'])->name('supplier_returns.dispatch');
            Route::post('supplier-returns/{return}/cancel', [SupplierController::class, 'cancelReturn'])->name('supplier_returns.cancel');
        });

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase_orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase_orders.show');
        Route::middleware('can:purchase_orders.manage')->group(function () {
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase_orders.store');
            Route::post('purchase-orders/{purchaseOrder}/order', [PurchaseOrderController::class, 'markOrdered'])->name('purchase_orders.order');
            Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase_orders.close');
            Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase_orders.cancel');
        });
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->middleware('can:purchase_orders.receive')->name('purchase_orders.receive');
    });

    $resource('brands', BrandController::class, 'brands.view', 'brands.manage', 'brands.manage', 'brands.manage');
    $resource('health_issues', HealthIssueController::class, 'health_issues.view', 'health_issues.manage', 'health_issues.manage', 'health_issues.manage');
    $resource('products', ProductController::class, 'products.view', 'products.create', 'products.update', 'products.delete');

    Route::get('reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');

    Route::middleware('can:orders.view')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        // orders.update_status, or orders.cancel for a cancellation (checked by the controller).
        Route::put('orders/{order}/update-status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    });
    Route::patch('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->middleware('can:payments.verify')->name('orders.paymentStatus');
    Route::get('files/payment-receipt/{order}', [PrivateFileController::class, 'paymentReceipt'])->middleware('can:payments.view')->name('files.paymentReceipt');

    Route::middleware('can:refunds.view')->group(function () {
        Route::get('returns', [ReturnRequestController::class, 'adminindex'])->name('returns.index');
        Route::get('returns/{id}', [ReturnRequestController::class, 'adminshow'])->name('returns.show');
        Route::post('returns/{id}/accept', [ReturnRequestController::class, 'accept'])->middleware('can:refunds.approve')->name('returns.accept');
        Route::post('returns/{id}/reject', [ReturnRequestController::class, 'reject'])->middleware('can:refunds.approve')->name('returns.reject');
        Route::get('files/return-image/{image}', [PrivateFileController::class, 'returnImage'])->name('files.returnImage');
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
        Route::post('payments/{payment}/refunds', [RefundController::class, 'store'])->middleware('can:refunds.create')->name('refunds.store');
        Route::middleware('can:refunds.process')->group(function () {
            Route::post('refunds/{refund}/process', [RefundController::class, 'process'])->name('refunds.process');
            Route::post('refunds/{refund}/reconcile', [RefundController::class, 'reconcile'])->name('refunds.reconcile');
            Route::post('refunds/{refund}/resolve', [RefundController::class, 'resolve'])->name('refunds.resolve');
            Route::post('refunds/{refund}/cancel', [RefundController::class, 'cancel'])->name('refunds.cancel');
        });
    });

    // Loyalty: the legacy per-order points page, plus the loyalty programme (ledger, rewards, settings).
    Route::middleware('can:loyalty.view')->group(function () {
        Route::get('points', [PointController::class, 'index'])->name('points.index');
        Route::patch('/points/{order}/redeem', [PointController::class, 'redeemPoints'])->middleware('can:loyalty.manage')->name('points.redeem');
        Route::patch('/dashboard/points/redeem-multiple', [PointController::class, 'redeemMultiple'])->middleware('can:loyalty.manage')->name('points.redeemMultiple');

        Route::prefix('loyalty')->name('loyalty.')->group(function () {
            Route::get('/', [LoyaltyDashboardController::class, 'overview'])->name('overview');
            Route::get('customers', [LoyaltyDashboardController::class, 'customers'])->name('customers');
            Route::get('transactions', [LoyaltyDashboardController::class, 'transactions'])->name('transactions');
            Route::get('expiring', [LoyaltyDashboardController::class, 'expiring'])->name('expiring');
            Route::post('customers/{customer}/adjust', [LoyaltyDashboardController::class, 'adjust'])->middleware('can:loyalty.adjust_points')->name('adjust');

            Route::get('settings', [LoyaltySettingsController::class, 'edit'])->middleware('can:loyalty.settings')->name('settings.edit');
            Route::put('settings', [LoyaltySettingsController::class, 'update'])->middleware('can:loyalty.settings')->name('settings.update');

            Route::get('rewards', [RewardController::class, 'index'])->name('rewards.index');
            Route::middleware('can:loyalty.manage_rewards')->group(function () {
                Route::get('rewards/create', [RewardController::class, 'create'])->name('rewards.create');
                Route::post('rewards', [RewardController::class, 'store'])->name('rewards.store');
                Route::get('rewards/{reward}/edit', [RewardController::class, 'edit'])->name('rewards.edit');
                Route::put('rewards/{reward}', [RewardController::class, 'update'])->name('rewards.update');
                Route::post('rewards/{reward}/toggle', [RewardController::class, 'toggle'])->name('rewards.toggle');
                Route::delete('rewards/{reward}', [RewardController::class, 'destroy'])->name('rewards.destroy');
            });

            Route::get('redemptions', [RedemptionController::class, 'index'])->name('redemptions.index');
            Route::middleware('can:loyalty.manage')->group(function () {
                Route::post('customers/{customer}/redeem', [RedemptionController::class, 'store'])->name('redemptions.store');
                Route::post('redemptions/{redemption}/approve', [RedemptionController::class, 'approve'])->name('redemptions.approve');
                Route::post('redemptions/{redemption}/fulfil', [RedemptionController::class, 'fulfil'])->name('redemptions.fulfil');
                Route::post('redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->name('redemptions.cancel');
            });
        });
    });

    Route::middleware('can:marketing.view')->group(function () use ($resource) {
        Route::get('marketing', [MarketingDashboardController::class, 'index'])->name('marketing.index');
        $resource('banners', BannerController::class, 'marketing.view', 'banners.manage', 'banners.manage', 'banners.manage');
        Route::post('flash_sales/{flash_sale}/toggle', [FlashSaleController::class, 'toggle'])->middleware('can:flash_sales.manage')->name('flash_sales.toggle');
        $resource('flash_sales', FlashSaleController::class, 'marketing.view', 'flash_sales.manage', 'flash_sales.manage', 'flash_sales.manage');
        Route::post('coupons/{coupon}/toggle', [CouponsController::class, 'toggle'])->middleware('can:coupons.manage')->name('coupons.toggle');
        $resource('coupons', CouponsController::class, 'marketing.view', 'coupons.manage', 'coupons.manage', 'coupons.manage');
        // Promotions (the table and route names keep their historical name, discounts).
        Route::middleware('can:promotions.manage')->group(function () {
            Route::post('discounts/{discount}/toggle', [DiscountController::class, 'toggle'])->name('discounts.toggle');
            Route::post('discounts/{discount}/duplicate', [DiscountController::class, 'duplicate'])->name('discounts.duplicate');
            Route::post('discounts/{discount}/simulate', [DiscountController::class, 'simulate'])->name('discounts.simulate');
        });
        $resource('discounts', DiscountController::class, 'marketing.view', 'promotions.manage', 'promotions.manage', 'promotions.manage');
        $resource('campaigns', CampaignController::class, 'marketing.view', 'campaigns.manage', 'campaigns.manage', 'campaigns.manage', ['except' => ['show']]);
        $resource('OpeningAd', OpeningAdController::class, 'marketing.view', 'banners.manage', 'banners.manage', 'banners.manage', ['names' => 'OpeningAd']);
    });

    Route::middleware('can:delivery.view')->group(function () {
        // The old delivery prices screen now lives under delivery zones.
        Route::get('delivery_prices', fn () => redirect()->route('dashboard.delivery.zones.index'))->name('delivery_prices.index');
        Route::get('cities', [LocationAdminController::class, 'index'])->name('cities.index');
        Route::middleware('can:locations.manage')->group(function () {
            Route::post('cities', [LocationAdminController::class, 'storeCity'])->name('cities.store');
            Route::put('cities/{city}', [LocationAdminController::class, 'updateCity'])->name('cities.update');
            Route::post('cities/{city}/areas', [LocationAdminController::class, 'storeArea'])->name('areas.store');
            Route::put('areas/{area}', [LocationAdminController::class, 'updateArea'])->name('areas.update');
        });

        Route::prefix('delivery')->name('delivery.')->group(function () {
            Route::get('zones', [DeliveryZoneController::class, 'index'])->name('zones.index');
            Route::middleware('can:delivery.manage_zones')->group(function () {
                Route::post('zones', [DeliveryZoneController::class, 'store'])->name('zones.store');
                Route::put('zones/{zone}', [DeliveryZoneController::class, 'update'])->name('zones.update');
                Route::delete('zones/{zone}', [DeliveryZoneController::class, 'destroy'])->name('zones.destroy');
                Route::post('zones/{zone}/rates', [DeliveryZoneController::class, 'storeRate'])->name('rates.store');
                Route::put('rates/{rate}', [DeliveryZoneController::class, 'updateRate'])->name('rates.update');
                Route::delete('rates/{rate}', [DeliveryZoneController::class, 'destroyRate'])->name('rates.destroy');
            });

            Route::get('providers', [DeliveryProviderController::class, 'index'])->name('providers.index');
            Route::middleware('can:delivery.manage_providers')->group(function () {
                Route::post('providers', [DeliveryProviderController::class, 'store'])->name('providers.store');
                Route::put('providers/{provider}', [DeliveryProviderController::class, 'update'])->name('providers.update');
                Route::post('providers/{provider}/status-maps', [DeliveryProviderController::class, 'storeStatusMap'])->name('statusMaps.store');
                Route::delete('status-maps/{map}', [DeliveryProviderController::class, 'destroyStatusMap'])->name('statusMaps.destroy');
            });

            Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
            Route::middleware('can:delivery.manage_shipments')->group(function () {
                Route::post('shipments/{shipment}/assign', [ShipmentController::class, 'assign'])->name('shipments.assign');
                Route::post('shipments/{shipment}/events', [ShipmentController::class, 'addEvent'])->name('shipments.events');
                Route::post('shipments/{shipment}/settle-cod', [ShipmentController::class, 'settleCod'])->name('shipments.settleCod');
                Route::post('orders/{order}/shipments', [ShipmentController::class, 'createForOrder'])->name('shipments.create');
            });
        });
    });

    Route::middleware('can:payments.view')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/events', [PaymentController::class, 'events'])->name('payments.events');
        Route::get('payments/reports', [PaymentController::class, 'reports'])->name('payments.reports');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
        Route::post('payments/{payment}/collect', [PaymentController::class, 'collect'])->middleware('can:payments.collect')->name('payments.collect');
        Route::post('payments/{payment}/refresh', [PaymentController::class, 'refresh'])->middleware('can:payments.collect')->name('payments.refresh');
        Route::get('cod', [CodController::class, 'index'])->name('cod.index');
        Route::post('cod/settlements', [CodController::class, 'settle'])->middleware('can:payments.settle_cod')->name('cod.settle');
        Route::get('cod/settlements/{settlement}', [CodController::class, 'show'])->name('cod.settlements.show');
        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment_methods.index');
        Route::post('payment-methods', [PaymentMethodController::class, 'store'])->middleware('can:payment_methods.manage')->name('payment_methods.store');
        Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->middleware('can:payment_methods.manage')->name('payment_methods.update');
    });

    // A single settings row: no create or delete.
    Route::get('settings', [SettingController::class, 'index'])->middleware('can:settings.view')->name('settings.index');
    Route::post('settings', [SettingController::class, 'store'])->middleware('can:settings.update')->name('settings.store');
    Route::put('settings/{setting}', [SettingController::class, 'update'])->middleware('can:settings.update')->name('settings.update');

    Route::middleware('can:health_content.view')->prefix('health-content')->name('health_content.')->group(function () {
        Route::get('vaccines', [VaccineScheduleController::class, 'index'])->name('vaccines.index');
        Route::get('pregnancy', [PregnancyContentController::class, 'index'])->name('pregnancy.index');
        Route::middleware('can:health_content.manage')->group(function () {
            Route::post('vaccines', [VaccineScheduleController::class, 'store'])->name('vaccines.store');
            Route::put('vaccines/{item}', [VaccineScheduleController::class, 'update'])->name('vaccines.update');
            Route::delete('vaccines/{item}', [VaccineScheduleController::class, 'destroy'])->name('vaccines.destroy');
            Route::post('pregnancy', [PregnancyContentController::class, 'store'])->name('pregnancy.store');
            Route::put('pregnancy/{content}', [PregnancyContentController::class, 'update'])->name('pregnancy.update');
            Route::delete('pregnancy/{content}', [PregnancyContentController::class, 'destroy'])->name('pregnancy.destroy');
        });
    });

    // Admins, roles and the audit log. AdminAccountService / RoleService also refuse escalation
    // (granting what the actor does not hold, touching super admins, editing one's own access).
    Route::get('admins', [AdminUserController::class, 'index'])->middleware('can:admins.view')->name('admins.index');
    Route::get('admins/{admin}', [AdminUserController::class, 'show'])->whereNumber('admin')->middleware('can:admins.view')->name('admins.show');
    Route::post('admins', [AdminUserController::class, 'store'])->middleware('can:admins.create')->name('admins.store');
    Route::put('admins/{admin}', [AdminUserController::class, 'update'])->middleware('can:admins.update')->name('admins.update');
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('can:audit_log.view')->name('audit.index');

    Route::middleware('can:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])->name('roles.duplicate');
            Route::post('roles/{role}/toggle', [RoleController::class, 'toggle'])->name('roles.toggle');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
            // Any permission, not only the role's own (custom-key child bindings are scoped by default).
            Route::patch('roles/{role}/permissions/{permission:slug}', [RoleController::class, 'setPermission'])->withoutScopedBindings()->name('roles.permission');
        });
        Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    });

    // Import & export, started from each data's own page (x-admin.data-transfer): every action
    // checks that entity's <entity>.import / .export permission.
    Route::middleware('can:data_transfer')->prefix('data')->name('data.')->group(function () {
        Route::get('templates/{entity}', [DataTransferController::class, 'template'])->name('template');
        Route::get('{entity}/history', [DataTransferController::class, 'history'])->where('entity', '[a-z_]+')->name('history');
        Route::post('imports', [DataTransferController::class, 'upload'])->middleware('throttle:20,1')->name('imports.store');
        Route::get('imports/{importRun}', [DataTransferController::class, 'showImport'])->name('imports.show');
        Route::post('imports/{importRun}/confirm', [DataTransferController::class, 'confirm'])->name('imports.confirm');
        Route::post('imports/{importRun}/cancel', [DataTransferController::class, 'cancelImport'])->name('imports.cancel');
        Route::get('imports/{importRun}/files/{kind}', [DataTransferController::class, 'importFile'])->whereIn('kind', ['original', 'errors', 'results'])->name('imports.file');
        Route::post('exports', [DataTransferController::class, 'export'])->middleware('throttle:20,1')->name('exports.store');
        Route::get('exports/{exportRun}/download', [DataTransferController::class, 'download'])->name('exports.download');
    });
});

Route::prefix('dashboard/categories')->name('dashboard.categories.')->middleware('can:categories.view')->group(function () {
    Route::get('/', [CategoryController::class, 'index'])->name('index');
    Route::middleware('can:categories.manage')->group(function () {
        Route::get('/create', [CategoryController::class, 'create'])->name('create');
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::put('/{category}', [CategoryController::class, 'update'])->name('update');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('destroy');
        Route::post('{category}/storeSubcategory', [CategoryController::class, 'storeSubcategory'])->name('storeSubcategory');
    });
});

// Customers' health records: only admins with health_data.view; every view is audited.
Route::prefix('dashboard/health-results')->middleware('can:health_data.view')->group(function () {
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
