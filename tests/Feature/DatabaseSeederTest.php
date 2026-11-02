<?php

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Major;
use App\Models\Transaction;
use App\Models\User;

test('the seeder creates demo staff, majors and customers and can run twice', function () {
    $this->seed();
    $this->seed();

    expect(Major::count())->toBe(8)
        ->and(User::pluck('username')->sort()->values()->all())->toBe(['admin', 'supervisor', 'teller'])
        ->and(Customer::count())->toBe(3)
        ->and(Transaction::count())->toBe(3)
        ->and(AccountingJournal::count())->toBe(6);
});

test('seeded balances match the transaction history and the journal', function () {
    $this->seed();

    $customers = Customer::all();
    $savingsCredit = AccountingJournal::where('account_code', AccountCode::CUSTOMER_SAVINGS)->where('position', Position::CREDIT)->sum('amount');
    $savingsDebit = AccountingJournal::where('account_code', AccountCode::CUSTOMER_SAVINGS)->where('position', Position::DEBIT)->sum('amount');

    expect($customers->sum('balance'))->toBe(2250000)
        ->and($savingsCredit - $savingsDebit)->toBe($customers->sum('balance'))
        ->and($customers->every(fn ($customer) => $customer->balance === $customer->transactions()->latest('id')->value('balance_after')))->toBeTrue();
});

test('opening balances are recorded in yesterday\'s approved shift, so today starts clean', function () {
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-10-27 08:00:00'));
    $this->seed();

    $teller = User::firstWhere('username', 'teller');
    $report = App\Models\DailyReport::sole();

    expect($report->teller_id)->toBe($teller->id)
        ->and($report->report_date->toDateString())->toBe('2026-10-26')
        ->and($report->status)->toBe(App\Enums\ReportStatus::APPROVED)
        ->and($report->opening_balance)->toBe(100000)
        ->and($report->physical_balance)->toBe(2350000)
        ->and($report->cashTransfer->amount)->toBe(2350000)
        ->and(app(App\Services\DailyReportService::class)->activeShift($teller))->toBeNull()
        ->and(Customer::orderBy('nis')->pluck('account_number')->all())->toBe(['RPL-2026-001', 'DKV-2026-001', 'RPL-2026-002'])
        ->and(now()->toDateTimeString())->toBe('2026-10-27 08:00:00');
});

test('demo accounts can log in and the teller can open today\'s shift', function () {
    $this->seed();

    $token = $this->postJson('/api/login', ['username' => 'teller', 'password' => 'password'])->assertOk()->json('data.token');
    // Seeded customers are pending: no Firebase account and no PIN until they activate the app.
    expect(Customer::where('nis', '20260001')->first())
        ->isActivated()->toBeFalse()
        ->pin->toBeNull();

    $this->withToken($token)->postJson('/api/teller/shift/open', ['denominations' => ['50000' => 2]])->assertCreated();
});
