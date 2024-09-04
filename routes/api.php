<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BendingForming\BendingFormingController;
use App\Http\Controllers\Api\LaserCutting\LaserCuttingController;
use App\Http\Controllers\Api\Materials\MaterialsController;
use App\Http\Controllers\Api\Nav\ServiceController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\PowderCutting\PowderCuttingController;
use App\Http\Controllers\Api\Tasks\TaskController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::middleware([EnsureFrontendRequestsAreStateful::class])->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::group(['prefix'=>'admin', 'middleware' => 'admin'],function (){
            Route::resource('/', AdminController::class);
        });


        Route::group(['prefix'=>'orders', 'middleware' => 'check.order'], function () {
            Route::resource('order', OrderController::class);
        });

        Route::resource('/roles', RoleController::class);
    });
    Route::group(['prefix'=>'nav'],function (){
        Route::resource('services', ServiceController::class);
    });
    Route::group(['prefix'=>'materials'],function (){
        Route::resource('/', MaterialsController::class);
    });
});

