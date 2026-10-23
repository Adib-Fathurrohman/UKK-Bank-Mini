<?php

use App\Enums\AccountCode;
use App\Enums\CashTransferStatus;
use App\Enums\Position;
use App\Enums\ReportStatus;
use App\Enums\TransactionTypes;
use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('transaction belongs to a customer and the teller who executed it', function () {
    $teller = User::factory()->teller()->create();
    $customer = Customer::factory()->create(['balance' => 50000]);

    $transaction = Transaction::create([
        'user_id' => $teller->id,
        'customer_id' => $customer->id,
        'type' => TransactionTypes::DEPOSIT,
        'amount' => 25000,
        'balance_before' => 50000,
        'balance_after' => 75000,
    ]);

    expect($transaction->fresh()->type)->toBe(TransactionTypes::DEPOSIT)
        ->and($transaction->customer->is($customer))->toBeTrue()
        ->and($transaction->teller->is($teller))->toBeTrue()
        ->and($customer->transactions)->toHaveCount(1)
        ->and($teller->transactions)->toHaveCount(1);
});

test('journal entries belong to a transaction and cast their enums', function () {
    $transaction = Transaction::create([
        'user_id' => User::factory()->teller()->create()->id,
        'customer_id' => Customer::factory()->create()->id,
        'type' => TransactionTypes::DEPOSIT,
        'amount' => 10000,
        'balance_before' => 0,
        'balance_after' => 10000,
    ]);

    $transaction->accountingJournals()->createMany([
        ['account_code' => AccountCode::CASH, 'position' => Position::DEBIT, 'amount' => 10000],
        ['account_code' => AccountCode::CUSTOMER_SAVINGS, 'position' => Position::CREDIT, 'amount' => 10000],
    ]);

    $journal = AccountingJournal::where('position', Position::DEBIT)->first();

    expect($transaction->accountingJournals)->toHaveCount(2)
        ->and($journal->account_code)->toBe(AccountCode::CASH)
        ->and($journal->position)->toBe(Position::DEBIT)
        ->and($journal->transaction->is($transaction))->toBeTrue();
});

test('daily report links to its teller and reviewing supervisor', function () {
    $teller = User::factory()->teller()->create();
    $supervisor = User::factory()->supervisor()->create();

    $report = DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-10-23',
        'status' => ReportStatus::APPROVED,
        'supervisor_id' => $supervisor->id,
        'reviewed_at' => now(),
    ]);

    expect($report->fresh()->status)->toBe(ReportStatus::APPROVED)
        ->and($report->report_date->toDateString())->toBe('2026-10-23')
        ->and($report->teller->is($teller))->toBeTrue()
        ->and($report->supervisor->is($supervisor))->toBeTrue()
        ->and($teller->dailyReports)->toHaveCount(1)
        ->and($supervisor->reviewedDailyReports)->toHaveCount(1);
});

test('cash transfer defaults to pending and links to its teller', function () {
    $teller = User::factory()->teller()->create();

    $transfer = CashTransfer::create(['teller_id' => $teller->id, 'amount' => 100000]);

    expect($transfer->fresh()->status)->toBe(CashTransferStatus::PENDING)
        ->and($transfer->teller->is($teller))->toBeTrue()
        ->and($transfer->supervisor)->toBeNull()
        ->and($teller->cashTransfers)->toHaveCount(1);
});

test('staff password is hashed, cast, and never serialized', function () {
    $user = User::factory()->admin()->create(['password' => 'secret-password']);

    expect($user->role)->toBe(UserRoles::ADMIN)
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($user->toArray())->not->toHaveKeys(['password', 'remember_token']);
});

test('customer pin is hashed on save and never serialized', function () {
    $customer = Customer::factory()->create(['pin' => '654321']);

    expect(Hash::check('654321', $customer->getAuthPassword()))->toBeTrue()
        ->and($customer->toArray())->not->toHaveKey('pin')
        ->and($customer->toJson())->not->toContain($customer->pin);
});
