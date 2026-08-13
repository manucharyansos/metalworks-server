<?php

use App\Http\Controllers\Api\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'detect.device', 'setlocale'])->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::patch('/', [ProfileController::class, 'update']);
    Route::patch('/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:10,1');
    Route::get('/orders', [ProfileController::class, 'orders']);
    Route::get('/factory-work', [ProfileController::class, 'factoryWork']);
});
