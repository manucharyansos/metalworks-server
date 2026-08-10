<?php

use App\Http\Controllers\Api\Admin\AdminFactoryOrderController;
use App\Http\Controllers\Api\Admin\AdminOperationsController;
use App\Http\Controllers\Api\Admin\AdminOrderExportController;
use App\Http\Controllers\Api\File\FactoryFileExtensionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('dashboard', [AdminOperationsController::class, 'dashboard']);
        Route::get('dashboard/orders', [AdminOperationsController::class, 'orders']);
        Route::get('dashboard/orders/export', AdminOrderExportController::class)
            ->middleware('throttle:10,1');

        Route::put(
            'factory-orders/{factoryOrder}/operator',
            [AdminFactoryOrderController::class, 'updateOperator']
        )->whereNumber('factoryOrder');

        Route::get('factory-file-extensions', [FactoryFileExtensionController::class, 'index']);
        Route::post('factory-file-extensions', [FactoryFileExtensionController::class, 'store']);
        Route::put(
            'factory-file-extensions/{factoryFileExtension}',
            [FactoryFileExtensionController::class, 'update']
        )->whereNumber('factoryFileExtension');
        Route::delete(
            'factory-file-extensions/{factoryFileExtension}',
            [FactoryFileExtensionController::class, 'destroy']
        )->whereNumber('factoryFileExtension');
    });
