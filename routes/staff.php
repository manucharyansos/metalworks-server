<?php

use App\Http\Controllers\Api\Materials\MaterialCategoryController;
use App\Http\Controllers\Api\Workers\WorkersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'detect.device'])
    ->prefix('staff')
    ->group(function () {
        Route::get('worker-options', [WorkersController::class, 'options']);
        Route::get('material-options', [MaterialCategoryController::class, 'options']);
    });
