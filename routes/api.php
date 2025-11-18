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
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware(['auth:sanctum', 'detect.device'])->group(function () {

        Route::get('user',   [AuthController::class, 'me']);
        Route::post('logout',[AuthController::class, 'logout']);

        /**
         * ─── ADMIN DASHBOARD ─────────────────────────────────
         */
        Route::group(['prefix' => 'admin', 'middleware' => 'admin'], function () {
            Route::resource('/', AdminController::class);

            Route::get('order', [OrderController::class, 'index'])
                ->middleware('permission:orders.view');

            Route::post('order',            [OrderController::class, 'store']);
            Route::get('order/{order}',     [OrderController::class, 'show']);
            Route::put('order/{order}',     [OrderController::class, 'update']);
            Route::patch('order/{order}',   [OrderController::class, 'update']);
            Route::delete('order/{order}',  [OrderController::class, 'destroy']);

            Route::post('orders/update/{order}', [OrderController::class, 'update'])
                ->name('orders.update');

            Route::post('/factory-orders/{id}/confirm', [OrderController::class, 'confirm']);


            Route::get('file-extensions', [FileExtensionController::class, 'index']);
            Route::get('file-extensions/create', [FileExtensionController::class, 'create']);
            Route::post('file-extensions', [FileExtensionController::class, 'store']);
            Route::get('file-extensions/{fileExtension}', [FileExtensionController::class, 'show']);
            Route::get('file-extensions/{fileExtension}/edit', [FileExtensionController::class, 'edit']);
            Route::put('file-extensions/{fileExtension}', [FileExtensionController::class, 'update']);
            Route::delete('file-extensions/{fileExtension}', [FileExtensionController::class, 'destroy']);

            Route::get('laser-file-extension', [LaserFileExtensionController::class, 'index']);
            Route::get('laser-file-extension/create', [LaserFileExtensionController::class, 'create']);
            Route::post('laser-file-extension', [LaserFileExtensionController::class, 'store']);
            Route::get('laser-file-extension/{fileExtension}', [LaserFileExtensionController::class, 'show']);
            Route::get('laser-file-extension/{fileExtension}/edit', [LaserFileExtensionController::class, 'edit']);
            Route::put('laser-file-extension/{fileExtension}', [LaserFileExtensionController::class, 'update']);
            Route::delete('laser-file-extension/{fileExtension}', [LaserFileExtensionController::class, 'destroy']);

            Route::get('bend-file-extension', [BendFileExtensionController::class, 'index']);
            Route::get('bend-file-extension/create', [BendFileExtensionController::class, 'create']);
            Route::post('bend-file-extension', [BendFileExtensionController::class, 'store']);
            Route::get('bend-file-extension/{fileExtension}', [BendFileExtensionController::class, 'show']);
            Route::get('bend-file-extension/{fileExtension}/edit', [BendFileExtensionController::class, 'edit']);
            Route::put('bend-file-extension/{fileExtension}', [BendFileExtensionController::class, 'update']);
            Route::delete('bend-file-extension/{fileExtension}', [BendFileExtensionController::class, 'destroy']);
        });

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

        Route::group(['prefix' => 'users', 'middleware' => 'admin'], function () {
            Route::get('/',       [UserController::class, 'index']);
            Route::post('/',      [UserController::class, 'store']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::patch('/{user}', [UserController::class, 'update']);
            Route::delete('/{user}', [UserController::class, 'destroy']);

            Route::get('/{user}/permissions', [UserPermissionController::class, 'show']);
            Route::put('/{user}/permissions', [UserPermissionController::class, 'update']);
        });

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

        Route::group(['prefix' => 'materials'], function () {
            Route::get('/', [MaterialController::class, 'index'])
                ->middleware('permission:materials.view');
            Route::get('/{material}', [MaterialController::class, 'show'])
                ->middleware('permission:materials.view');

            Route::post('/', [MaterialController::class, 'store'])
                ->middleware('permission:materials.create');

            Route::post('/{material}', [MaterialController::class, 'update'])
                ->middleware('permission:materials.update');

            Route::put('/{material}', [MaterialController::class, 'update'])
                ->middleware('permission:materials.update');
            Route::patch('/{material}', [MaterialController::class, 'update'])
                ->middleware('permission:materials.update');

            Route::delete('/{material}', [MaterialController::class, 'destroy'])
                ->middleware('permission:materials.delete');
        });

        Route::group(['prefix' => 'factories'], function () {

            Route::get('factory', [FactoryController::class, 'index'])
                ->middleware('permission:factory.view');

            Route::get('factory/{factory}', [FactoryController::class, 'show'])
                ->middleware('permission:factory.view');

            Route::post('factory', [FactoryController::class, 'store']);
            Route::put('factory/{factory}', [FactoryController::class, 'update']);
            Route::patch('factory/{factory}', [FactoryController::class, 'update']);
            Route::delete('factory/{factory}', [FactoryController::class, 'destroy']);

            Route::get('getFile/{path}', [FactoryController::class, 'getFile'])
                ->where('path', '.*')
                ->middleware('permission:factory.download');

            Route::get('download/{path}', [FactoryController::class, 'downloadFile'])
                ->where('path', '.*')
                ->middleware('permission:factory.download');

            Route::put('updateOrder/{order}', [FactoryController::class, 'updateOrder'])
                ->middleware('permission:factory.order_update');

            Route::get('getOrdersByFactories', [FactoryController::class, 'getOrdersByFactories']);
            Route::put('confirmOrderStatus/{id}', [FactoryController::class, 'confirmOrderStatus']);
        });

        Route::group(['prefix' => 'engineers', 'middleware' => 'engineer'], function () {

            Route::get('engineer',            [EngineerController::class, 'index'])->middleware('permission:orders.view');
            Route::get('engineer/create',     [EngineerController::class, 'create'])->middleware('permission:orders.create');
            Route::post('engineer',           [EngineerController::class, 'store'])->middleware('permission:orders.create');
            Route::get('engineer/{engineer}', [EngineerController::class, 'show'])->middleware('permission:orders.view');
            Route::get('engineer/{engineer}/edit', [EngineerController::class, 'edit'])->middleware('permission:orders.view');
            Route::put('engineer/{engineer}',      [EngineerController::class, 'update'])->middleware('permission:orders.update');
            Route::delete('engineer/{engineer}',   [EngineerController::class, 'destroy'])->middleware('permission:orders.delete');

            Route::get('pmps', [PmpController::class, 'index'])
                ->middleware('permission:pmp.view');

            Route::post('pmps', [PmpController::class, 'store'])
                ->middleware('permission:pmp.create');

            Route::get('pmps/{pmp}', [PmpController::class, 'show'])
                ->middleware('permission:pmp.view');

            Route::put('pmps/{pmp}', [PmpController::class, 'update'])
                ->middleware('permission:pmp.update');

            Route::delete('pmps/{pmp}', [PmpController::class, 'destroy'])
                ->middleware('permission:pmp.update');

            Route::post('pmps/{id}/remote-number', [PmpController::class, 'remoteNumber'])
                ->middleware('permission:pmp.create');

            Route::post('pmps/check-group', [PmpController::class, 'checkGroup'])
                ->middleware('permission:pmp_group.check_group');

            Route::post('pmps/check-group-name', [PmpController::class, 'checkGroupName'])
                ->middleware('permission:pmp_group.check_group_name');

            Route::post('pmps/check-pmp-by-remote-number/{id}', [PmpController::class, 'checkPmpByRemoteNumber'])
                ->middleware('permission:pmp_group.check_remote_number');

            Route::get('pmps/{id}/next-remote-number', [PmpController::class, 'nextRemoteNumber'])
                ->middleware('permission:pmp.view');
            Route::get('pmps/remote-number/{id}', [PmpController::class, 'showByRemoteNumber'])
                ->middleware('permission:pmp.view');

            Route::get('pmpFiles', [PmpFilesController::class, 'index'])
                ->middleware('permission:pmp_files.view');
            Route::get('pmpFiles/{file}', [PmpFilesController::class, 'show'])
                ->middleware('permission:pmp_files.view');

            Route::post('uploadPmpFile', [PmpFilesController::class, 'upload'])
                ->middleware('permission:pmp_files.upload');

            Route::delete('pmpFiles/{file}', [PmpFilesController::class, 'destroy'])
                ->middleware('permission:pmp_files.delete');

            Route::get('factories/{factoryId}/orders/{orderId}/files', [EngineerController::class, 'getFilesForFactoryAndOrder'])
                ->middleware('permission:pmp.view');
        });

        /**
         * ─── ROLES ──────────────────────────────────────────────
         * Front:
         *   get('/api/roles')
         */
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:roles.view');

        Route::group(['prefix' => 'roles', 'middleware' => 'admin'], function () {
            Route::post('/',         [RoleController::class, 'store']);
            Route::get('/{role}',   [RoleController::class, 'show']);
            Route::put('/{role}',   [RoleController::class, 'update']);
            Route::patch('/{role}', [RoleController::class, 'update']);
            Route::delete('/{role}',[RoleController::class, 'destroy']);
        });

        /**
         * ─── SINGLE ORDER (shared access for mail link) ────────
         */
        Route::get('orders/{id}', [OrderController::class, 'show'])
            ->name('orders.show')
            ->middleware('permission:orders.view');
    });
});

/**
 * ─── PUBLIC / MATERIALS & CATEGORIES ─────────────────────────
 */
Route::middleware('setlocale')->group(function () {

    Route::group(['prefix' => 'categories'], function () {
        Route::resource('materialGroup',      MaterialGroupController::class);
        Route::resource('materialCategories', MaterialCategoryController::class);
    });

    Route::get('material-categories', [MaterialCategoryController::class, 'index'])
        ->middleware('auth:sanctum', 'permission:material_categories.view');

    Route::apiResource('material-groups',     MaterialGroupController::class);
});

/**
 * ─── VISITOR STATS ───────────────────────────────────────────
 */
Route::middleware(['auth:sanctum', 'setlocale'])
    ->get('/visitor-stats', [VisitorController::class, 'getDeviceStats']);

/**
 * ─── PERMISSIONS (Admin only, առանց permission group, քանի որ config–ում չկա) ─
 */
Route::middleware(['auth:sanctum', 'setlocale', 'admin'])
    ->prefix('permissions')
    ->group(function () {
        Route::get('/',               [PermissionController::class, 'index']);
        Route::get('/{permission}',   [PermissionController::class, 'show']);
        Route::put('/{permission}',   [PermissionController::class, 'update']);
        Route::patch('/{permission}', [PermissionController::class, 'update']);
        Route::delete('/{permission}',[PermissionController::class, 'destroy']);

        Route::get('/map/all', function () {
            return response()->json(PermissionMap::all());
        });
    });
