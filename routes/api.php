<?php

use App\Http\Controllers\API\Admin\CustomerController;
use App\Http\Controllers\API\Admin\StaffController;
use App\Http\Controllers\API\Audit\JournalController;
use App\Http\Controllers\API\Audit\TransactionController as AuditTransactionController;
use App\Http\Controllers\API\Customer\AccountController as CustomerAccountController;
use App\Http\Controllers\API\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\API\MajorController;
use App\Http\Controllers\API\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\API\Supervisor\CashTransferController as SupervisorCashTransferController;
use App\Http\Controllers\API\Supervisor\DailyReportController as SupervisorDailyReportController;
use App\Http\Controllers\API\Teller\CashTransferController as TellerCashTransferController;
use App\Http\Controllers\API\Teller\CustomerLookupController;
use App\Http\Controllers\API\Teller\DailyReportController as TellerDailyReportController;
use App\Http\Controllers\API\Teller\TransactionController as TellerTransactionController;
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

    // Administrator and Supervisor: read-only account list and audit trail
    Route::middleware('staff:admin,supervisor')->group(function () {
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->withTrashed();

        Route::prefix('audit')->group(function () {
            Route::get('transactions', [AuditTransactionController::class, 'index']);
            Route::get('transactions/{transaction}', [AuditTransactionController::class, 'show']);
            Route::get('journals', [JournalController::class, 'index']);
            Route::get('journals/summary', [JournalController::class, 'summary']);
        });
    });

    // Teller only
    Route::middleware('staff:teller')->prefix('teller')->group(function () {
        Route::get('customers/lookup', CustomerLookupController::class);

        Route::get('transactions', [TellerTransactionController::class, 'index']);
        Route::post('deposits', [TellerTransactionController::class, 'deposit']);
        Route::post('withdrawals', [TellerTransactionController::class, 'withdraw']);

        Route::get('daily-reports/preview', [TellerDailyReportController::class, 'preview']);
        Route::get('daily-reports', [TellerDailyReportController::class, 'index']);
        Route::post('daily-reports', [TellerDailyReportController::class, 'store']);
        Route::get('daily-reports/{dailyReport}', [TellerDailyReportController::class, 'show']);

        Route::get('drawer', [TellerCashTransferController::class, 'drawer']);
        Route::get('cash-transfers', [TellerCashTransferController::class, 'index']);
        Route::post('cash-transfers', [TellerCashTransferController::class, 'store']);
    });

    // Supervisor only
    Route::middleware('staff:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
        Route::get('daily-reports', [SupervisorDailyReportController::class, 'index']);
        Route::get('daily-reports/{dailyReport}', [SupervisorDailyReportController::class, 'show']);
        Route::post('daily-reports/{dailyReport}/approve', [SupervisorDailyReportController::class, 'approve'])->name('daily-reports.approve');
        Route::post('daily-reports/{dailyReport}/reject', [SupervisorDailyReportController::class, 'reject'])->name('daily-reports.reject');

        Route::get('cash-transfers', [SupervisorCashTransferController::class, 'index']);
        Route::get('cash-transfers/{cashTransfer}', [SupervisorCashTransferController::class, 'show']);
        Route::post('cash-transfers/{cashTransfer}/approve', [SupervisorCashTransferController::class, 'approve'])->name('cash-transfers.approve');
        Route::post('cash-transfers/{cashTransfer}/reject', [SupervisorCashTransferController::class, 'reject'])->name('cash-transfers.reject');
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

    Route::get('balance', [CustomerAccountController::class, 'balance']);
    Route::get('transactions', [CustomerAccountController::class, 'transactions']);
    Route::get('transactions/{transaction}', [CustomerAccountController::class, 'transaction'])->whereNumber('transaction');
});
