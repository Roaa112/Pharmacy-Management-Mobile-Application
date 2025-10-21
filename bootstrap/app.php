<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))

    // Define rate limiter
    ->booting(function () {
        RateLimiter::for('api', function (Request $request) {
            $limit = (int) env('API_RATE_LIMIT', 60);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });
    })

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '/api',
        then: function () {

            // V1 API routes
            Route::prefix('api/v1')
                ->middleware(['api', 'throttle:api'])
                ->group(base_path('routes/api.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
