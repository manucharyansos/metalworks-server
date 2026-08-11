<?php

use App\Http\Controllers\Api\Workers\WorkersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'detect.device'])
    ->prefix('staff')
    ->group(function () {
        Route::get('worker-options', [WorkersController::class, 'options']);
    });
