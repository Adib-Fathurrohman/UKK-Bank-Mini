<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// These tests write through the query builder on purpose, so the database
// itself (not Eloquent or validation) has to reject the bad data.

function insertDailyReport(array $overrides = []): int
{
    return DB::table('daily_reports')->insertGetId(array_merge([
        'teller_id' => User::factory()->teller()->create()->id,
        'report_date' => '2026-10-23',
        'status' => 'open',
    ], $overrides));
}

function insertTransaction(array $overrides = []): int
{
    $tellerId = User::factory()->teller()->create()->id;

    return DB::table('transactions')->insertGetId(array_merge([
        'user_id' => $tellerId,
        'customer_id' => Customer::factory()->create()->id,
        'daily_report_id' => insertDailyReport(['teller_id' => $tellerId]),
        'type' => 'deposit',
        'amount' => 10000,
        'balance_before' => 0,
        'balance_after' => 10000,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function insertJournal(int $transactionId, array $overrides = []): void
{
    DB::table('accounting_journals')->insert(array_merge([
        'transaction_id' => $transactionId,
        'account_code' => '101',
        'position' => 'debit',
        'amount' => 10000,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('valid rows are accepted by the helpers above', function () {
    $transactionId = insertTransaction();
    insertJournal($transactionId, ['position' => 'debit', 'account_code' => '101']);
    insertJournal($transactionId, ['position' => 'credit', 'account_code' => '201']);

    $reportId = DB::table('transactions')->where('id', $transactionId)->value('daily_report_id');
    DB::table('cash_counts')->insert(['daily_report_id' => $reportId, 'stage' => 'opening', 'denomination' => 1000, 'quantity' => 1]);
    DB::table('cash_transfers')->insert([
        'daily_report_id' => $reportId,
        'amount' => 1000,
        'approved_by' => User::factory()->supervisor()->create()->id,
        'transferred_at' => now(),
    ]);

    expect(DB::table('accounting_journals')->where('transaction_id', $transactionId)->count())->toBe(2)
        ->and(DB::table('cash_counts')->count())->toBe(1)
        ->and(DB::table('cash_transfers')->count())->toBe(1);
});

test('transaction amount must be positive', function (int $amount) {
    insertTransaction(['amount' => $amount]);
})->with([0, -5000])->throws(QueryException::class);

test('journal amount must be positive', function (int $amount) {
    insertJournal(insertTransaction(), ['amount' => $amount]);
})->with([0, -5000])->throws(QueryException::class);

test('cash transfer amount must be positive', function () {
    DB::table('cash_transfers')->insert([
        'daily_report_id' => insertDailyReport(),
        'amount' => 0,
        'approved_by' => User::factory()->supervisor()->create()->id,
        'transferred_at' => now(),
    ]);
})->throws(QueryException::class);

test('a daily report moves cash to the vault at most once', function () {
    $reportId = insertDailyReport();
    $supervisorId = User::factory()->supervisor()->create()->id;

    foreach (range(1, 2) as $attempt) {
        DB::table('cash_transfers')->insert([
            'daily_report_id' => $reportId,
            'amount' => 1000,
            'approved_by' => $supervisorId,
            'transferred_at' => now(),
        ]);
    }
})->throws(QueryException::class);

test('cash counts need a positive quantity and denomination', function (array $row) {
    DB::table('cash_counts')->insert([
        'daily_report_id' => insertDailyReport(),
        'stage' => 'opening',
        ...$row,
    ]);
})->with([
    'zero quantity' => [['denomination' => 1000, 'quantity' => 0]],
    'zero denomination' => [['denomination' => 0, 'quantity' => 1]],
])->throws(QueryException::class);

test('a denomination is counted once per stage', function () {
    $reportId = insertDailyReport();

    foreach (range(1, 2) as $attempt) {
        DB::table('cash_counts')->insert(['daily_report_id' => $reportId, 'stage' => 'closing', 'denomination' => 1000, 'quantity' => 1]);
    }
})->throws(QueryException::class);

test('a correction must point at the transaction it reverses and its authorizer', function () {
    insertTransaction(['type' => 'deposit_correction']);
})->throws(QueryException::class);

test('an ordinary transaction cannot claim to correct another one', function () {
    $original = insertTransaction();

    insertTransaction(['corrects_transaction_id' => $original]);
})->throws(QueryException::class);

test('a transaction can only be corrected once', function () {
    $original = insertTransaction();
    $supervisorId = User::factory()->supervisor()->create()->id;

    foreach (range(1, 2) as $attempt) {
        insertTransaction(['type' => 'deposit_correction', 'corrects_transaction_id' => $original, 'authorized_by' => $supervisorId]);
    }
})->throws(QueryException::class);

test('every transaction belongs to a shift', function () {
    insertTransaction(['daily_report_id' => null]);
})->throws(QueryException::class);

test('customer balance can never be negative', function () {
    $customer = Customer::factory()->create();

    DB::table('customers')->where('id', $customer->id)->update(['balance' => -1]);
})->throws(QueryException::class);

test('a transaction cannot have two journal lines on the same side', function () {
    $transactionId = insertTransaction();

    insertJournal($transactionId, ['position' => 'debit']);
    insertJournal($transactionId, ['position' => 'debit', 'account_code' => '201']);
})->throws(QueryException::class);

test('journal account code must be a known account', function () {
    insertJournal(insertTransaction(), ['account_code' => '999']);
})->throws(QueryException::class);

test('a teller has only one shift per date', function () {
    $tellerId = User::factory()->teller()->create()->id;

    foreach (range(1, 2) as $attempt) {
        insertDailyReport(['teller_id' => $tellerId]);
    }
})->throws(QueryException::class);

test('a customer with transactions cannot be hard deleted', function () {
    $transactionId = insertTransaction();
    $customerId = DB::table('transactions')->where('id', $transactionId)->value('customer_id');

    Customer::find($customerId)->forceDelete();
})->throws(QueryException::class);

test('a teller with transactions cannot be hard deleted', function () {
    $transactionId = insertTransaction();
    $tellerId = DB::table('transactions')->where('id', $transactionId)->value('user_id');

    User::find($tellerId)->delete();
})->throws(QueryException::class);

test('a transaction with journal entries cannot be deleted', function () {
    $transactionId = insertTransaction();
    insertJournal($transactionId);

    DB::table('transactions')->where('id', $transactionId)->delete();
})->throws(QueryException::class);
