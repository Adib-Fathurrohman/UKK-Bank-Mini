<?php

use App\Http\Controllers\API\Admin\CustomerController;
use App\Http\Controllers\API\Admin\StaffController;
use App\Http\Controllers\API\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\API\MajorController;
use App\Http\Controllers\API\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\API\Teller\CustomerLookupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff portal (Administrator, Supervisor, Teller)
|--------------------------------------------------------------------------
*/

Route::post('staff/login', [StaffAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('staff/me', [StaffAuthController::class, 'me']);
    Route::post('staff/logout', [StaffAuthController::class, 'logout']);
    Route::get('majors', [MajorController::class, 'index']);

    // Administrator only
    Route::middleware('staff:admin')->prefix('admin')->group(function () {
        Route::apiResource('staff', StaffController::class);

        Route::post('customers', [CustomerController::class, 'store']);
        Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update']);
        Route::post('customers/{customer}/deactivate', [CustomerController::class, 'deactivate']);
    });

    // Administrator and Supervisor: read-only account list
    Route::middleware('staff:admin,supervisor')->group(function () {
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->withTrashed();
    });

    // Teller only
    Route::middleware('staff:teller')->prefix('teller')->group(function () {
        Route::get('customers/lookup', CustomerLookupController::class);
    });
});

/*
|--------------------------------------------------------------------------
| Customer portal (Nasabah)
|--------------------------------------------------------------------------
*/

Route::post('customer/login', [CustomerAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'customer'])->prefix('customer')->group(function () {
    Route::get('me', [CustomerAuthController::class, 'me']);
    Route::post('logout', [CustomerAuthController::class, 'logout']);
});
