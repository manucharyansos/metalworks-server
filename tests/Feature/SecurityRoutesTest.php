<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityRoutesTest extends TestCase
{
    public function test_secure_file_routes_require_sanctum_authentication(): void
    {
        $pmp = $this->findRoute('GET', 'api/secure-files/pmp/{file}');
        $order = $this->findRoute('GET', 'api/secure-files/order/{file}');

        $this->assertRouteUsesMiddleware($pmp, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($order, 'auth:sanctum');
    }

    public function test_permission_management_routes_are_admin_only(): void
    {
        $route = $this->findRoute('PUT', 'api/users/{user}/permissions');

        $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($route, 'admin');
    }

    public function test_engineer_order_mutations_keep_role_and_permission_guards(): void
    {
        $update = $this->findRoute('PUT', 'api/engineers/engineer/{engineer}');
        $delete = $this->findRoute('DELETE', 'api/engineers/engineer/{engineer}');

        $this->assertRouteUsesMiddleware($update, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($update, 'engineer');
        $this->assertRouteUsesMiddleware($update, 'permission:orders.update');

        $this->assertRouteUsesMiddleware($delete, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($delete, 'engineer');
        $this->assertRouteUsesMiddleware($delete, 'permission:orders.delete');
    }

    public function test_factory_file_download_route_keeps_download_permission(): void
    {
        $route = $this->findRoute('GET', 'api/factories/download/{path}');

        $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($route, 'permission:factory.download');
    }

    private function findRoute(string $method, string $uri): LaravelRoute
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (LaravelRoute $route) =>
                in_array($method, $route->methods(), true) && $route->uri() === $uri
            );

        $this->assertNotNull($route, "Expected route {$method} {$uri} to exist.");

        return $route;
    }

    private function assertRouteUsesMiddleware(LaravelRoute $route, string $middleware): void
    {
        $this->assertContains(
            $middleware,
            $route->gatherMiddleware(),
            "Expected {$route->uri()} to use middleware [{$middleware}]."
        );
    }
}
