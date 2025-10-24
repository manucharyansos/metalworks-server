<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Client\ClientController;
use App\Http\Controllers\Api\Engineer\EngineerController;
use App\Http\Controllers\Api\Factory\EngineersController;
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
use App\Http\Controllers\Api\Product\ProductController;
use App\Http\Controllers\Api\Service\ServiceController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Work\WorkController;
use App\Http\Controllers\Api\Workers\WorkersController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\VisitorController;
use App\Http\Controllers\BasketController;
use App\Http\Controllers\CheckoutController;
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
            Route::get('/getStatus', [FactoryController::class, 'getStatus']);
        });

        Route::group(['prefix' => 'engineers', 'middleware' => 'engineer'], function () {
            Route::get('factories/{factoryId}/orders/{orderId}/files', [EngineerController::class, 'getFilesForFactoryAndOrder']);
            Route::resource('/engineer', EngineerController::class);
            Route::apiResource('pmps', PmpController::class);
            Route::post('pmps/remoteNumber/{id}', [PmpController::class, 'remoteNumber']);
            Route::post('/pmps/check-group', [PmpController::class, 'checkGroup']);
            Route::post('/pmps/check-group-name', [PmpController::class, 'checkGroupName']);
            Route::post('/pmps/check-pmp-by-remote-number/{id}', [PmpController::class, 'checkPmpByRemoteNumber']);
            Route::apiResource('pmpFiles', PmpFilesController::class);
            Route::post('uploadPmpFile', [PmpFilesController::class, 'upload']);
        });

        Route::resource('/roles', RoleController::class);

        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');

        Route::get('baskets/current', [BasketController::class, 'current'])->name('baskets.current');
        Route::apiResource('baskets', BasketController::class)->except(['create', 'edit']);
        Route::put('baskets/items/{itemId}', [BasketController::class, 'update'])->name('baskets.items.update');
        Route::delete('baskets/items/{itemId}', [BasketController::class, 'removeItem'])->name('baskets.items.remove');

        Route::get('/checkout', [CheckoutController::class, 'index']);
        Route::post('/checkout', [CheckoutController::class, 'store']);
    });

    Route::apiResource('services', ServiceController::class);
    Route::get('services/slug/{slug}', [ServiceController::class, 'showBySlug']);

    Route::apiResource('works', WorkController::class);
    Route::get('works/slug/{slug}', [WorkController::class, 'showBySlug']);


    Route::group(['prefix' => 'contacts'], function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
    });
});

Route::middleware('setlocale')->group(function () {

    Route::group(['prefix' => 'categories'], function () {
        Route::resource('/materialGroup', MaterialGroupController::class);
        Route::resource('/materialCategories', MaterialCategoryController::class);
    });

    Route::group(['prefix' => 'materials'], function () {
        Route::resource('/', MaterialController::class);
    });

    Route::group(['prefix' => 'products'], function () {
        Route::resource('/', ProductController::class, ['parameters' => ['' => 'id']]);
    });

});

Route::middleware(['auth:sanctum', 'setlocale'])
    ->get('/visitor-stats', [VisitorController::class, 'getDeviceStats']);
