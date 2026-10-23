<?php

use App\Http\Controllers\API\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\API\Staff\AuthController as StaffAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff portal (Administrator, Supervisor, Teller)
|--------------------------------------------------------------------------
*/

Route::prefix('staff')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::get('me', [StaffAuthController::class, 'me']);
        Route::post('logout', [StaffAuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Customer portal (Nasabah)
|--------------------------------------------------------------------------
*/

Route::prefix('customer')->group(function () {
    Route::post('login', [CustomerAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'customer'])->group(function () {
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::post('logout', [CustomerAuthController::class, 'logout']);
    });
});
