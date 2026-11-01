<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Admin;
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

        // One Gate per dashboard permission; roles decide (App\Enums\AdminRole).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn ($user) => $user instanceof Admin && $user->hasPermission($permission->value));
        }

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
            ]));
        });
    }
}
