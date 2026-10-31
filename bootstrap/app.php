<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))

    // Define rate limiters
    ->booting(function () {
        RateLimiter::for('api', function (Request $request) {
            $limit = (int) env('API_RATE_LIMIT', 60);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        // Login, registration and password-reset endpoints: limit per account and per IP
        // so credentials and OTP codes cannot be brute-forced.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('auth-email:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
            ];
        });
    })

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '/api',
        then: function () {

            // V1 API routes (throttle:api is already part of the "api" group)
            Route::prefix('api/v1')
                ->middleware(['api'])
                ->group(base_path('routes/api.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Apply the API rate limiter to both /api/* and /api/v1/*.
        $middleware->throttleApi();

        // Drop paging/sorting values that would make listing queries fail.
        $middleware->api(append: [\App\Http\Middleware\SanitizeListingParameters::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
