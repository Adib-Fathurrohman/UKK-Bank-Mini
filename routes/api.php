<?php

use App\Http\Controllers\API\Admin\CustomerController;
use App\Http\Controllers\API\Admin\StaffController;
use App\Http\Controllers\API\Audit\JournalController;
use App\Http\Controllers\API\Audit\TransactionController as AuditTransactionController;
use App\Http\Controllers\API\Customer\AccountController as CustomerAccountController;
use App\Http\Controllers\API\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\MajorController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\API\Supervisor\CashTransferController as SupervisorCashTransferController;
use App\Http\Controllers\API\Supervisor\DailyReportController as SupervisorDailyReportController;
use App\Http\Controllers\API\Teller\CustomerLookupController;
use App\Http\Controllers\API\Teller\DailyReportController as TellerDailyReportController;
use App\Http\Controllers\API\Teller\ShiftController;
use App\Http\Controllers\API\Teller\TransactionController as TellerTransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Login gateway for all roles
|--------------------------------------------------------------------------
| POST /login is the single entry point. The portal-specific endpoints
| below accept the same credentials and are kept for convenience.
*/

Route::middleware('throttle:login')->group(function () {
    Route::post('login', LoginController::class);
    Route::post('staff/login', [StaffAuthController::class, 'login']);
    Route::post('customer/login', [CustomerAuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| Staff portal (Administrator, Supervisor, Teller)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('staff/me', [StaffAuthController::class, 'me']);
    Route::post('staff/logout', [StaffAuthController::class, 'logout']);
    Route::get('majors', [MajorController::class, 'index']);
    Route::get('settings', [SettingsController::class, 'show']);

    // Administrator only
    Route::middleware('staff:admin')->prefix('admin')->group(function () {
        Route::post('staff', [StaffController::class, 'store']);
        Route::post('staff/import', [StaffController::class, 'import']);
        Route::match(['put', 'patch'], 'staff/{staff}', [StaffController::class, 'update']);
        Route::delete('staff/{staff}', [StaffController::class, 'destroy']);
        Route::post('staff/{staff}/block', [StaffController::class, 'block']);
        Route::post('staff/{staff}/unblock', [StaffController::class, 'unblock']);

        Route::match(['put', 'patch'], 'settings', [SettingsController::class, 'update']);

        Route::post('customers', [CustomerController::class, 'store']);
        Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update']);
        Route::post('customers/{customer}/deactivate', [CustomerController::class, 'deactivate']);
        Route::get('customers/{customer}/card', [CustomerController::class, 'card']);
    });

    // Administrator and Supervisor: read-only staff and account lists, audit trail
    Route::middleware('staff:admin,supervisor')->group(function () {
        Route::get('staff', [StaffController::class, 'index']);
        Route::get('staff/{staff}', [StaffController::class, 'show'])->whereNumber('staff');

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
        Route::post('transactions/{transaction}/correct', [TellerTransactionController::class, 'correct'])
            ->middleware('throttle:10,1');

        Route::get('shift', [ShiftController::class, 'show']);
        Route::post('shift/open', [ShiftController::class, 'open']);
        Route::post('shift/close', [ShiftController::class, 'close']);

        Route::get('daily-reports', [TellerDailyReportController::class, 'index']);
        Route::get('daily-reports/{dailyReport}', [TellerDailyReportController::class, 'show']);
    });

    // Supervisor only
    Route::middleware('staff:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
        Route::get('daily-reports', [SupervisorDailyReportController::class, 'index']);
        Route::get('daily-reports/{dailyReport}', [SupervisorDailyReportController::class, 'show']);
        Route::post('daily-reports/{dailyReport}/approve', [SupervisorDailyReportController::class, 'approve'])->name('daily-reports.approve');
        Route::post('daily-reports/{dailyReport}/hold', [SupervisorDailyReportController::class, 'hold'])->name('daily-reports.hold');
        Route::post('daily-reports/{dailyReport}/reject', [SupervisorDailyReportController::class, 'reject'])->name('daily-reports.reject');

        // Vault ledger: transfers are created by approving a daily report.
        Route::get('cash-transfers', [SupervisorCashTransferController::class, 'index']);
        Route::get('cash-transfers/{cashTransfer}', [SupervisorCashTransferController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| Customer portal (Nasabah)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'customer'])->prefix('customer')->group(function () {
    Route::get('me', [CustomerAuthController::class, 'me']);
    Route::post('logout', [CustomerAuthController::class, 'logout']);

    Route::get('balance', [CustomerAccountController::class, 'balance']);
    Route::get('card', [CustomerAccountController::class, 'card']);
    Route::post('pin', [CustomerAccountController::class, 'changePin']);
    Route::get('transactions', [CustomerAccountController::class, 'transactions']);
    Route::get('transactions/{transaction}', [CustomerAccountController::class, 'transaction'])->whereNumber('transaction');
});
