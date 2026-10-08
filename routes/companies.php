<?php

use App\Http\Controllers\Api\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('companies')->group(function () {
    Route::get('/', [CompanyController::class, 'index']);
    Route::post('/', [CompanyController::class, 'store']);
    Route::put('/{company}', [CompanyController::class, 'update'])->whereNumber('company');
    Route::get('/{company}/logo', [CompanyController::class, 'logo'])->whereNumber('company');
});
