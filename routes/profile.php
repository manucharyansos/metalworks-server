<?php

use App\Http\Controllers\Api\Profile\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'detect.device', 'setlocale'])->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::patch('/', [ProfileController::class, 'update']);

    Route::get('/identity', function (Request $request) {
        $user = $request->user();

        if (app(\App\Support\CompanyContext::class)->id()) $user->loadMissing(['role', 'factory', 'worker']);
        else $user->setRelation('role', null)->setRelation('factory', null)->setRelation('worker', null);

        $lastName = $user->last_name ?: $user->worker?->last_name;
        $phone = $user->phone ?: $user->worker?->phone;

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'last_name' => $lastName,
            'display_name' => trim(implode(' ', array_filter([$user->name, $lastName]))),
            'phone' => $phone,
            'email' => $user->email,
            'role' => $user->role?->name,
            'factory' => $user->factory?->name,
        ]);
    });

    Route::post('/email/code', [ProfileController::class, 'requestEmailCode'])
        ->middleware('throttle:10,1');
    Route::post('/email/confirm', [ProfileController::class, 'confirmEmailCode'])
        ->middleware('throttle:20,1');

    Route::patch('/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:10,1');
    Route::get('/orders', [ProfileController::class, 'orders']);
    Route::get('/factory-work', [ProfileController::class, 'factoryWork']);
});
