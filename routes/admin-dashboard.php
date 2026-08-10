<?php

use App\Http\Controllers\Api\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('dashboard/orders', [AdminController::class, 'orders']);
    });
