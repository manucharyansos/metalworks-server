<?php

use App\Http\Controllers\Api\File\SecurePmpFileController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::middleware([
    EnsureFrontendRequestsAreStateful::class,
    'setlocale',
    'auth:sanctum',
    'detect.device',
])->prefix('secure-files')->group(function () {
    Route::get('pmp/{file}', [SecurePmpFileController::class, 'show'])
        ->whereNumber('file');
});
