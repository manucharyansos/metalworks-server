<?php

use App\Http\Controllers\Api\File\SecureOrderFileController;
use App\Http\Controllers\Api\File\SecurePmpFileController;
use Illuminate\Support\Facades\Route;

// RouteServiceProvider already applies the `api` middleware group here,
// including Sanctum's stateful handling, throttling, bindings, and locale.
Route::middleware([
    'auth:sanctum',
    'detect.device',
])->prefix('secure-files')->group(function () {
    Route::get('pmp/{file}', [SecurePmpFileController::class, 'show'])
        ->whereNumber('file');

    Route::get('order/{file}', [SecureOrderFileController::class, 'show'])
        ->whereNumber('file');
});
