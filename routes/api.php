<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Client\ClientController;
use App\Http\Controllers\Api\Engineer\EngineerController;
use App\Http\Controllers\Api\Factory\FactoryController;
use App\Http\Controllers\Api\File\BendFileExtensionController;
use App\Http\Controllers\Api\File\FileController;
use App\Http\Controllers\Api\File\FileExtensionController;
use App\Http\Controllers\Api\File\LaserFileExtensionController;
use App\Http\Controllers\Api\Materials\MaterialCategoryController;
use App\Http\Controllers\Api\Materials\MaterialController;
use App\Http\Controllers\Api\Materials\MaterialGroupController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\PMP\PmpController;
use App\Http\Controllers\Api\PMP\PmpFilesController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Workers\WorkersController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\VisitorController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Լեզուն (locale) now applies GLOBALLY via 'setlocale'.
*/

Route::middleware([EnsureFrontendRequestsAreStateful::class, 'setlocale'])->group(function () {

    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware(['auth:sanctum', 'detect.device'])->group(function () {

        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::group(['prefix' => 'admin', 'middleware' => 'admin'], function () {
            Route::resource('/', AdminController::class);
            Route::resource('order', OrderController::class);
            Route::post('orders/update/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::resource('file-extensions', FileExtensionController::class);
            Route::resource('laser-file-extension', LaserFileExtensionController::class);
            Route::resource('bend-file-extension', BendFileExtensionController::class);
        });

        Route::group(['prefix' => 'orders', 'middleware' => 'check.order'], function () {
            Route::resource('order', OrderController::class);
        });

        Route::group(['prefix' => 'users'], function () {
            Route::resource('/', UserController::class);
        });

        Route::group(['prefix' => 'clients'], function () {
            Route::resource('client', ClientController::class);
        });

        Route::group(['prefix' => 'workers'], function () {
            Route::apiResource('/', WorkersController::class);
            Route::put('/{worker}', [WorkersController::class, 'update']);
        });

        Route::group(['prefix' => 'factories'], function () {
            Route::apiResource('factory', FactoryController::class);
            Route::get('getFile/{path}', [FactoryController::class, 'getFile'])->where('path', '.*');
            Route::get('/download/{path}', [FactoryController::class, 'downloadFile'])->where('path', '.*');
            Route::get('/getOrdersByFactories', [FactoryController::class, 'getOrdersByFactories']);
            Route::put('/updateOrder/{order}', [FactoryController::class, 'updateOrder']);
            Route::put('/confirmOrderStatus/{id}', [FactoryController::class, 'confirmOrderStatus']);
//            Route::get('/getStatus', [FactoryController::class, 'getStatus']);
        });

//        Route::group(['prefix' => 'engineers', 'middleware' => 'engineer'], function () {
//            Route::get('factories/{factoryId}/orders/{orderId}/files', [EngineerController::class, 'getFilesForFactoryAndOrder']);
//            Route::resource('/engineer', EngineerController::class);
////            Route::apiResource('pmps', PmpController::class);
////            Route::post('pmps/remoteNumber/{id}', [PmpController::class, 'remoteNumber']);
////            Route::post('/pmps/check-group', [PmpController::class, 'checkGroup']);
////            Route::post('/pmps/check-group-name', [PmpController::class, 'checkGroupName']);
////            Route::post('/pmps/check-pmp-by-remote-number/{id}', [PmpController::class, 'checkPmpByRemoteNumber']);
//            Route::apiResource('pmpFiles', PmpFilesController::class);
//            Route::post('uploadPmpFile', [PmpFilesController::class, 'upload']);
//
//            Route::apiResource('pmps', \App\Http\Controllers\Api\PMP\PmpController::class);
//            Route::post('pmps/{id}/remote-number', [\App\Http\Controllers\Api\PMP\PmpController::class, 'remoteNumber']);
//            Route::get('pmps/{id}/next-remote-number', [\App\Http\Controllers\Api\PMP\PmpController::class, 'nextRemoteNumber']);
//
//            Route::post('pmps/check-group', [\App\Http\Controllers\Api\PMP\PmpController::class, 'checkGroup']);
//            Route::post('pmps/check-group-name', [\App\Http\Controllers\Api\PMP\PmpController::class, 'checkGroupName']);
//            Route::post('pmps/check-pmp-by-remote-number/{id}', [\App\Http\Controllers\Api\PMP\PmpController::class, 'checkPmpByRemoteNumber']);
//        });
        Route::group(['prefix' => 'engineers', 'middleware' => 'engineer'], function () {
            Route::resource('/engineer', EngineerController::class);

            // PMP
            Route::apiResource('pmps', PmpController::class);
            Route::post('pmps/{id}/remote-number',       [PmpController::class, 'remoteNumber']);
            Route::get('pmps/{id}/next-remote-number',   [PmpController::class, 'nextRemoteNumber']);

            Route::post('pmps/check-group',                      [PmpController::class, 'checkGroup']);
            Route::post('pmps/check-group-name',                 [PmpController::class, 'checkGroupName']);
            Route::post('pmps/check-pmp-by-remote-number/{id}',  [PmpController::class, 'checkPmpByRemoteNumber']);

            // PMP Files
            Route::apiResource('pmpFiles', PmpFilesController::class)->only(['destroy', 'index', 'show']);
            Route::post('uploadPmpFile', [PmpFilesController::class, 'upload']);

            // Optional: files by factory+order viewer
            Route::get('factories/{factoryId}/orders/{orderId}/files', [EngineerController::class, 'getFilesForFactoryAndOrder']);
            Route::get('pmps/remote-number/{id}', [PmpController::class, 'showByRemoteNumber']);
        });

        Route::resource('/roles', RoleController::class);

        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    });

});

Route::middleware('setlocale')->group(function () {

    Route::group(['prefix' => 'categories'], function () {
        Route::resource('/materialGroup', MaterialGroupController::class);
        Route::resource('/materialCategories', MaterialCategoryController::class);
    });

    Route::apiResource('material-groups', MaterialGroupController::class);
    Route::apiResource('material-categories', MaterialCategoryController::class);
    Route::apiResource('materials', MaterialController::class);

    Route::group(['prefix' => 'materials'], function () {
        Route::resource('/', MaterialController::class);
    });

});

Route::middleware(['auth:sanctum', 'setlocale'])
    ->get('/visitor-stats', [VisitorController::class, 'getDeviceStats']);
