<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Client\ClientController;
use App\Http\Controllers\Api\Factory\FactoryController;
use App\Http\Controllers\Api\Nav\ServiceController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\VisitorController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\RoleController;
use App\Models\Visitor;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::middleware([EnsureFrontendRequestsAreStateful::class])->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum',)->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::group(['prefix'=>'admin', 'middleware' => 'admin'],function (){
            Route::resource('/', AdminController::class);
            Route::resource('order', OrderController::class);
            Route::post('update/{order}', [OrderController::class, 'update']);
        });

        Route::group(['prefix'=>'orders', ['middleware' => 'check.order', 'detect.device']], function () {
            Route::resource('order', OrderController::class);
//            Route::resource('materials', MaterialsController::class);
        });

        Route::group(['prefix'=>'users'], function () {
            Route::resource('/', UserController::class);
        });

        Route::group(['prefix'=>'clients'], function () {
            Route::resource('client', ClientController::class);
        });

        Route::group(['prefix'=>'factories'], function () {
            Route::apiResource('factory', FactoryController::class);
            Route::get('/getOrdersByFactories', [FactoryController::class, 'getOrdersByFactories']);
            Route::put('/updateOrder/{order}', [FactoryController::class, 'updateOrder']);
            Route::get('/getStatus', [FactoryController::class, 'getStatus']);
        });

//        Route::group(['prefix'=>'mails'], function () {
//            Route::get('send', [MailController::class, 'send']);
//        });

        Route::resource('/roles', RoleController::class);

        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    });
    Route::group(['prefix'=>'categories'],function (){
//        Route::resource('/category', CategoryController::class);
    });
    Route::group(['prefix'=>'materials', 'middleware' => 'detect.device'],function (){
//        Route::resource('/', MaterialsController::class);
    });

    Route::post('/contact', [ContactController::class, 'store']);
});

Route::middleware('auth:sanctum')->get('/visitor-stats', [VisitorController::class, 'getDeviceStats']);

