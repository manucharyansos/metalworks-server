<?php

use App\Http\Controllers\Api\Admin\AdminOperationsController;
use App\Http\Controllers\Api\Admin\AdminOrderExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('dashboard', [AdminOperationsController::class, 'dashboard']);
        Route::get('dashboard/orders', [AdminOperationsController::class, 'orders']);
        Route::get('dashboard/orders/export', AdminOrderExportController::class)
            ->middleware('throttle:10,1');
    });
