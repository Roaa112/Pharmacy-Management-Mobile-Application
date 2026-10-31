<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Walks the real route table so a newly added customer endpoint cannot ship without authentication.
 */
class RouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoints that are intentionally public. */
    private const PUBLIC_API_ACTIONS = [
        'UserController@login',
        'UserController@createUser',
        'UserController@verify',
        'UserController@sendResetOtp',
        'UserController@verifyOtp',
        'UserController@resetPasswordAuth',
        'ProductReviewController@index',
        'Closure',
    ];

    private function apiRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->values()
            ->all();
    }

    private function actionName(Route $route): string
    {
        return class_basename($route->getActionName());
    }

    private function urlFor(Route $route): string
    {
        return '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }

    public function test_every_customer_endpoint_rejects_requests_without_a_token(): void
    {
        $checked = 0;

        foreach ($this->apiRoutes() as $route) {
            if (!in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $response = $this->json($method, $this->urlFor($route));

            $this->assertSame(401, $response->status(), "{$method} {$route->uri()} answered {$response->status()} without a token");
            $checked++;
        }

        // 62 protected routes (31 under /api and the same 31 under /api/v1).
        $this->assertGreaterThanOrEqual(60, $checked);
    }

    public function test_only_catalog_and_sign_in_endpoints_are_public(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            $action = $this->actionName($route);
            $isCatalogRead = $route->methods() === ['GET', 'HEAD']
                && preg_match('/^Api(Product|Category|Brand|Banner|Setting|HealthIssue|OpeningAd)Controller@|^DeliveryPriceController@indexapi$/', $action);

            $this->assertTrue(
                $isCatalogRead || in_array($action, self::PUBLIC_API_ACTIONS, true),
                "{$route->uri()} ({$action}) is public"
            );
        }
    }

    public function test_every_routed_controller_method_exists(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure') {
                continue;
            }

            [$class, $method] = explode('@', $action);
            $this->assertTrue(method_exists($class, $method), "{$route->uri()} points at missing {$action}");
        }
    }
}
