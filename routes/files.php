<?php

use App\Http\Controllers\Api\File\FactoryFileExtensionController;
use App\Http\Controllers\Api\File\SecureLegacyFileController;
use App\Http\Controllers\Api\File\SecureOrderFileController;
use App\Http\Controllers\Api\File\SecurePmpFileController;
use Illuminate\Support\Facades\Route;

// RouteServiceProvider already applies the `api` middleware group here,
// including Sanctum's stateful handling, throttling, bindings, and locale.
Route::middleware([
    'auth:sanctum',
    'detect.device',
])->group(function () {
    // Read-only upload policy for engineers. This exposes only factory names,
    // codes and allowed extensions; mutation remains admin-only.
    Route::get('factory-file-policies', [FactoryFileExtensionController::class, 'index'])
        ->middleware('permission:pmp_files.view');

    Route::prefix('secure-files')->group(function () {
        Route::get('pmp/{file}', [SecurePmpFileController::class, 'show'])
            ->whereNumber('file');

        Route::get('order/{file}', [SecureOrderFileController::class, 'show'])
            ->whereNumber('file');

        Route::get('path/{path}', [SecureLegacyFileController::class, 'show'])
            ->where('path', '.*');
    });
});
