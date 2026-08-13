<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityRoutesTest extends TestCase
{
    public function test_secure_file_routes_require_sanctum_authentication(): void
    {
        $pmp = $this->findRoute('GET', 'api/secure-files/pmp/{file}');
        $order = $this->findRoute('GET', 'api/secure-files/order/{file}');
        $legacyPath = $this->findRoute('GET', 'api/secure-files/path/{path}');

        $this->assertRouteUsesMiddleware($pmp, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($order, 'auth:sanctum');
        $this->assertRouteUsesMiddleware($legacyPath, 'auth:sanctum');
    }

    public function test_admin_operations_dashboard_routes_are_admin_only(): void
    {
        $dashboard = $this->findRoute('GET', 'api/admin/dashboard');
        $orders = $this->findRoute('GET', 'api/admin/dashboard/orders');
        $export = $this->findRoute('GET', 'api/admin/dashboard/orders/export');
        $reassign = $this->findRoute('PUT', 'api/admin/factory-orders/{factoryOrder}/operator');

        foreach ([$dashboard, $orders, $export, $reassign] as $route) {
            $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
            $this->assertRouteUsesMiddleware($route, 'admin');
        }

        $this->assertRouteUsesMiddleware($export, 'throttle:10,1');
    }

    public function test_factory_file_extension_management_is_admin_only(): void
    {
        $index = $this->findRoute('GET', 'api/admin/factory-file-extensions');
        $store = $this->findRoute('POST', 'api/admin/factory-file-extensions');
        $update = $this->findRoute('PUT', 'api/admin/factory-file-extensions/{factoryFileExtension}');
        $delete = $this->findRoute('DELETE', 'api/admin/factory-file-extensions/{factoryFileExtension}');

        foreach ([$index, $store, $update, $delete] as $route) {
            $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
            $this->assertRouteUsesMiddleware($route, 'admin');
        }
    }

    public function test_staff_directory_and_individual_permission_management_are_admin_only(): void
    {
        $staffDirectory = $this->findRoute('GET', 'api/users');
        $permissionView = $this->findRoute('GET', 'api/users/{user}/permissions');
        $permissionUpdate = $this->findRoute('PUT', 'api/users/{user}/permissions');

        foreach ([$staffDirectory, $permissionView, $permissionUpdate] as $route) {
            $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
            $this->assertRouteUsesMiddleware($route, 'admin');
        }
    }

    public function test_staff_form_option_routes_require_authentication(): void
    {
        $workerOptions = $this->findRoute('GET', 'api/staff/worker-options');
        $materialOptions = $this->findRoute('GET', 'api/staff/material-options');

        foreach ([$workerOptions, $materialOptions] as $route) {
            $this->assertRouteUsesMiddleware($route, 'auth:sanctum');
            $this->assertRouteUsesMiddleware($route, 'detect.device');
        }
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

    public function test_dangerous_uploads_are_rejected_before_controller_logic(): void
    {
        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/login', [
                'email' => 'test@example.com',
                'password' => 'not-used',
                'payload' => UploadedFile::fake()->create(
                    'shell.php',
                    1,
                    'application/x-httpd-php'
                ),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('files');
    }

    public function test_dangerous_double_extension_uploads_are_rejected(): void
    {
        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/login', [
                'email' => 'test@example.com',
                'password' => 'not-used',
                'payload' => UploadedFile::fake()->create(
                    'shell.php.jpg',
                    1,
                    'image/jpeg'
                ),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('files');
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
