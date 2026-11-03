<?php

namespace App\Providers;

use App\Models\Admin;
use App\Modules\Admin\Authorization\PermissionRegistry;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Notifications\Push\FcmPushGateway;
use App\Notifications\Push\LogPushGateway;
use App\Notifications\Push\PushGateway;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PUSH_DRIVER: log (default) or fcm.
        $this->app->bind(PushGateway::class, fn () => config('services.push.driver') === 'fcm'
            ? new FcmPushGateway()
            : new LogPushGateway());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Paginator::useBootstrapFive();

        // One Gate per permission; the admin's role (database) decides. Only admins hold any.
        foreach (PermissionRegistry::slugs() as $slug) {
            Gate::define($slug, fn ($user) => $user instanceof Admin && $user->hasPermission($slug));
        }
        // The module names used before permissions were fine-grained (`can:orders`, @can('orders'))
        // mean "may open the module".
        foreach (PermissionRegistry::MODULES as $module => [, $viewPermission]) {
            Gate::define($module, fn ($user) => $user instanceof Admin && $user->hasPermission($viewPermission));
        }
        // The import & export centre opens for anyone allowed to import or export something.
        Gate::define('data_transfer', fn ($user) => $user instanceof Admin
            && collect($user->permissionSlugs())->contains(fn ($slug) => PermissionRegistry::isDataTransfer($slug)));

        // Counts shown in the admin sidebar badges and the "needs attention" menu. Cached for a
        // minute: they are hints, and every admin page would otherwise run these queries.
        View::composer('admin.layout', function ($view) {
            $view->with('adminAttention', Cache::remember('admin.attention', 60, fn () => [
                'pending_orders' => Order::where('status', 'pending')->count(),
                'pending_returns' => ReturnRequest::where('status', 'pending')->count(),
                'out_of_stock' => ProductSize::where('stock', '<=', 0)->whereHas('product')->count(),
                'payments_to_verify' => Order::where('payment_status', 'awaiting_verification')->where('status', '!=', 'canceled')->count(),
                'shipments_attention' => Shipment::where('needs_attention', true)->count(),
                'pending_refunds' => \App\Models\Refund::whereIn('status', ['pending', 'failed'])->count(),
                'pending_redemptions' => \App\Models\LoyaltyRedemption::whereIn('status', \App\Models\LoyaltyRedemption::OPEN)->count(),
            ]));
        });
    }
}
