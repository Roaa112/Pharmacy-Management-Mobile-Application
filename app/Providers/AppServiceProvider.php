<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */


public function boot()
{
    Paginator::useBootstrapFive();

    // Counts shown in the admin sidebar badges and the "needs attention" menu.
    View::composer('admin.layout', function ($view) {
        $view->with('adminAttention', [
            'pending_orders' => Order::where('status', 'pending')->count(),
            'pending_returns' => ReturnRequest::where('status', 'pending')->count(),
            'out_of_stock' => ProductSize::where('stock', '<=', 0)->whereHas('product')->count(),
        ]);
    });
}

}
