<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Client\ClientController;
use App\Http\Controllers\Api\Engineer\EngineerController;
use App\Http\Controllers\Api\Factory\FactoryController;
use App\Http\Controllers\Api\File\BendFileExtensionController;
use App\Http\Controllers\Api\File\FileExtensionController;
use App\Http\Controllers\Api\File\LaserFileExtensionController;
use App\Http\Controllers\Api\Materials\MaterialCategoryController;
use App\Http\Controllers\Api\Materials\MaterialController;
use App\Http\Controllers\Api\Materials\MaterialGroupController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PMP\PmpController;
use App\Http\Controllers\Api\PMP\PmpFilesController;
use App\Http\Controllers\Api\UserPermissionController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Workers\WorkersController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\VisitorController;
use App\Support\PermissionMap;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::middleware([EnsureFrontendRequestsAreStateful::class, 'setlocale'])->group(function () {

    /**
     * ─── AUTH ────────────────────────────────────────────────
     */
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware(['auth:sanctum', 'detect.device'])->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        /**
         * ─── ADMIN DASHBOARD ─────────────────────────────────
         */
        Route::group(['prefix' => 'admin', 'middleware' => 'admin'], function () {
            Route::resource('/', AdminController::class);

            // Orders (admin panel)
            Route::resource('order', OrderController::class);

            Route::post('orders/update/{order}', [OrderController::class, 'update'])
                ->name('orders.update');

            // File extensions
//            Route::resource('file-extensions', FileExtensionController::class);
            Route::get('file-extensions', [FileExtensionController::class, 'index'])
                ->middleware('permission:file_extensions.view');
            Route::get('file-extensions/create', [FileExtensionController::class, 'create'])
                ->middleware('permission:file_extensions.create');
            Route::post('file-extensions', [FileExtensionController::class, 'store'])
                ->middleware('permission:file_extensions.create');
            Route::get('file-extensions/{fileExtension}', [FileExtensionController::class, 'show'])
                ->middleware('permission:file_extensions.view');
            Route::get('file-extensions/{fileExtension}/edit', [FileExtensionController::class, 'edit'])
                ->middleware('permission:file_extensions.update');
            Route::put('file-extensions/{fileExtension}',      [FileExtensionController::class, 'update'])
                ->middleware('permission:file_extensions.update');
            Route::delete('file-extensions/{fileExtension}',   [FileExtensionController::class, 'destroy'])
                ->middleware('permission:file_extensions.delete');
//            Route::resource('laser-file-extension', LaserFileExtensionController::class);

            Route::get('laser-file-extension',              [LaserFileExtensionController::class, 'index'])
                ->middleware('permission:file_extensions.view');
            Route::get('laser-file-extension/create',       [LaserFileExtensionController::class, 'create'])
                ->middleware('permission:file_extensions.create');
            Route::post('laser-file-extension',             [LaserFileExtensionController::class, 'store'])
                ->middleware('permission:file_extensions.create');
            Route::get('laser-file-extension/{fileExtension}', [LaserFileExtensionController::class, 'show'])
                ->middleware('permission:file_extensions.view');
            Route::get('laser-file-extension/{fileExtension}/edit', [LaserFileExtensionController::class, 'edit'])
                ->middleware('permission:file_extensions.update');
            Route::put('laser-file-extension/{fileExtension}',      [LaserFileExtensionController::class, 'update'])
                ->middleware('permission:file_extensions.update');
            Route::delete('laser-file-extension/{fileExtension}',   [LaserFileExtensionController::class, 'destroy'])
                ->middleware('permission:file_extensions.delete');

//            Route::resource('bend-file-extension', BendFileExtensionController::class);

            Route::get('bend-file-extension',              [BendFileExtensionController::class, 'index'])
                ->middleware('permission:file_extensions.view');
            Route::get('bend-file-extension/create',       [BendFileExtensionController::class, 'create'])
                ->middleware('permission:file_extensions.create');
            Route::post('bend-file-extension',             [BendFileExtensionController::class, 'store'])
                ->middleware('permission:file_extensions.create');
            Route::get('bend-file-extension/{fileExtension}', [BendFileExtensionController::class, 'show'])
                ->middleware('permission:file_extensions.view');
            Route::get('bend-file-extension/{fileExtension}/edit', [BendFileExtensionController::class, 'edit'])
                ->middleware('permission:file_extensions.update');
            Route::put('bend-file-extension/{fileExtension}',      [BendFileExtensionController::class, 'update'])
                ->middleware('permission:file_extensions.update');
            Route::delete('bend-file-extension/{fileExtension}',   [BendFileExtensionController::class, 'destroy'])
                ->middleware('permission:file_extensions.delete');

        });

        /**
         * ─── ORDERS (Manager / Staff) ─────────────────────────
         */
        Route::group(['prefix' => 'orders', 'middleware' => ['check.order']], function () {
            Route::get('order', [OrderController::class, 'index'])
                ->middleware('permission:orders.view');
            Route::get('order/{order}', [OrderController::class, 'show'])
                ->middleware('permission:orders.view');
            Route::post('order', [OrderController::class, 'store'])
                ->middleware('permission:orders.create');
            Route::put('order/{order}', [OrderController::class, 'update'])
                ->middleware('permission:orders.update');
            Route::patch('order/{order}', [OrderController::class, 'update'])
                ->middleware('permission:orders.update');
            Route::delete('order/{order}', [OrderController::class, 'destroy'])
                ->middleware('permission:orders.delete');
        });

        /**
         * ─── USERS & USER PERMISSIONS ─────────────────────────
         */
        Route::group(['prefix' => 'users'], function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store'])
                ->middleware('permission:users.create');
            Route::get('/{user}', [UserController::class, 'show'])
                ->middleware('permission:users.view');
            Route::put('/{user}', [UserController::class, 'update'])
                ->middleware('permission:users.update');
            Route::patch('/{user}', [UserController::class, 'update'])
                ->middleware('permission:users.update');
            Route::delete('/{user}', [UserController::class, 'destroy'])
                ->middleware('permission:users.delete');

            // User-specific permissions
            Route::get('/{user}/permissions', [UserPermissionController::class, 'show'])
                ->middleware('permission:users.update');
            Route::put('/{user}/permissions', [UserPermissionController::class, 'update'])
                ->middleware('permission:users.update');
        });

        /**
         * ─── CLIENTS ───────────────────────────────────────────
         */
        Route::group(['prefix' => 'clients'], function () {
            Route::get('client', [ClientController::class, 'index'])
                ->middleware('permission:clients.view');
            Route::post('client', [ClientController::class, 'store'])
                ->middleware('permission:clients.create');
            Route::get('client/{client}', [ClientController::class, 'show'])
                ->middleware('permission:clients.view');
            Route::put('client/{client}', [ClientController::class, 'update'])
                ->middleware('permission:clients.update');
            Route::patch('client/{client}', [ClientController::class, 'update'])
                ->middleware('permission:clients.update');
            Route::delete('client/{client}', [ClientController::class, 'destroy'])
                ->middleware('permission:clients.delete');
        });

        /**
         * ─── WORKERS ───────────────────────────────────────────
         */
        Route::group(['prefix' => 'workers'], function () {
            Route::get('/', [WorkersController::class, 'index'])
                ->middleware('permission:workers.view');
            Route::post('/', [WorkersController::class, 'store'])
                ->middleware('permission:workers.create');
            Route::get('/{worker}', [WorkersController::class, 'show'])
                ->middleware('permission:workers.view');
            Route::put('/{worker}', [WorkersController::class, 'update'])
                ->middleware('permission:workers.update');
            Route::patch('/{worker}', [WorkersController::class, 'update'])
                ->middleware('permission:workers.update');
            Route::delete('/{worker}', [WorkersController::class, 'destroy'])
                ->middleware('permission:workers.delete');
        });

        /**
         * ─── FACTORIES ─────────────────────────────────────────
         */
        Route::group(['prefix' => 'factories'], function () {
            Route::get('factory', [FactoryController::class, 'index'])
                ->middleware('permission:factories.view');
            Route::post('factory', [FactoryController::class, 'store'])
                ->middleware('permission:factories.create');
            Route::get('factory/{factory}', [FactoryController::class, 'show'])
                ->middleware('permission:factories.view');
            Route::put('factory/{factory}', [FactoryController::class, 'update'])
                ->middleware('permission:factories.update');
            Route::patch('factory/{factory}', [FactoryController::class, 'update'])
                ->middleware('permission:factories.update');
            Route::delete('factory/{factory}', [FactoryController::class, 'destroy'])
                ->middleware('permission:factories.delete');

            Route::get('getFile/{path}', [FactoryController::class, 'getFile'])
                ->where('path', '.*')
                ->middleware('permission:factories.view');
            Route::get('download/{path}', [FactoryController::class, 'downloadFile'])
                ->where('path', '.*')
                ->middleware('permission:factories.view');
            Route::get('getOrdersByFactories', [FactoryController::class, 'getOrdersByFactories'])
                ->middleware('permission:factories.view');
            Route::put('updateOrder/{order}', [FactoryController::class, 'updateOrder'])
                ->middleware('permission:factories.update');
            Route::put('confirmOrderStatus/{id}', [FactoryController::class, 'confirmOrderStatus'])
                ->middleware('permission:factories.update');
        });

        /**
         * ─── ENGINEERS + PMP ───────────────────────────────────
         */
        Route::group(['prefix' => 'engineers', 'middleware' => 'engineer'], function () {
            Route::get('engineer', [EngineerController::class, 'index'])
                ->middleware('permission:engineers.view');

            Route::get('engineer/create', [EngineerController::class, 'create'])
                ->middleware('permission:engineers.create');

            Route::post('engineer', [EngineerController::class, 'store'])
                ->middleware('permission:engineers.create');

            Route::get('engineer/{engineer}', [EngineerController::class, 'show'])
                ->middleware('permission:engineers.view');

            Route::get('engineer/{engineer}/edit', [EngineerController::class, 'edit'])
                ->middleware('permission:engineers.update');

            Route::put('engineer/{engineer}', [EngineerController::class, 'update'])
                ->middleware('permission:engineers.update');

            Route::delete('engineer/{engineer}', [EngineerController::class, 'destroy'])
                ->middleware('permission:engineers.delete');


            Route::get('pmps', [PmpController::class, 'index'])
                ->middleware('permission:pmps.view');
            Route::post('pmps', [PmpController::class, 'store'])
                ->middleware('permission:pmps.create');
            Route::get('pmps/{pmp}', [PmpController::class, 'show'])
                ->middleware('permission:pmps.view');
            Route::put('pmps/{pmp}', [PmpController::class, 'update'])
                ->middleware('permission:pmps.update');
            Route::delete('pmps/{pmp}', [PmpController::class, 'destroy'])
                ->middleware('permission:pmps.delete');


            Route::get('pmpFiles', [PmpFilesController::class, 'index'])
                ->middleware('permission:pmps.view');
            Route::get('pmpFiles/{file}', [PmpFilesController::class, 'show'])
                ->middleware('permission:pmps.view');
            Route::post('uploadPmpFile', [PmpFilesController::class, 'upload'])
                ->middleware('permission:pmps.create');
            Route::delete('pmpFiles/{file}', [PmpFilesController::class, 'destroy'])
                ->middleware('permission:pmps.delete');


            Route::post('pmps/{id}/remote-number', [PmpController::class, 'remoteNumber'])
                ->middleware('permission:pmps.update');

            Route::get('pmps/{id}/next-remote-number', [PmpController::class, 'nextRemoteNumber'])
                ->middleware('permission:pmps.view');

            Route::post('pmps/check-group', [PmpController::class, 'checkGroup'])
                ->middleware('permission:pmps.view');

            Route::post('pmps/check-group-name', [PmpController::class, 'checkGroupName'])
                ->middleware('permission:pmps.view');

            Route::post('pmps/check-pmp-by-remote-number/{id}', [PmpController::class, 'checkPmpByRemoteNumber'])
                ->middleware('permission:pmps.view');

            Route::get('pmps/remote-number/{id}', [PmpController::class, 'showByRemoteNumber'])
                ->middleware('permission:pmps.view');

            Route::get('factories/{factoryId}/orders/{orderId}/files', [EngineerController::class, 'getFilesForFactoryAndOrder'])
                ->middleware('permission:pmps.view');
        });

        /**
         * ─── ROLES ──────────────────────────────────────────────
         */
        Route::resource('roles', RoleController::class)
            ->middleware(['admin', 'permission:roles.manage']);

        /**
         * ─── SINGLE ORDER (shared access) ───────────────────────
         */
        Route::get('orders/{id}', [OrderController::class, 'show'])
            ->name('orders.show')
            ->middleware('permission:orders.view');
    });
});

/**
 * ─── PUBLIC / MATERIALS ────────────────────────────────────────────────
 */
Route::middleware('setlocale')->group(function () {
    Route::group(['prefix' => 'categories'], function () {
        Route::resource('materialGroup', MaterialGroupController::class);
        Route::resource('materialCategories', MaterialCategoryController::class);
    });

    Route::apiResource('material-groups', MaterialGroupController::class);
    Route::apiResource('material-categories', MaterialCategoryController::class);
    Route::apiResource('materials', MaterialController::class);
});

/**
 * ─── VISITOR STATS ─────────────────────────────────────────────────────
 */
Route::middleware(['auth:sanctum', 'setlocale'])
    ->get('/visitor-stats', [VisitorController::class, 'getDeviceStats']);

/**
 * ─── PERMISSIONS (Admin only, final correct version) ───────────────────
 */
Route::middleware(['auth:sanctum', 'setlocale', 'admin'])
    ->prefix('permissions')
    ->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::get('/{permission}', [PermissionController::class, 'show']);
        Route::put('/{permission}', [PermissionController::class, 'update']);
        Route::patch('/{permission}', [PermissionController::class, 'update']);
        Route::delete('/{permission}', [PermissionController::class, 'destroy']);

        // Frontend permission map
        Route::get('/map/all', function () {
            return response()->json(PermissionMap::all());
        });
    });
