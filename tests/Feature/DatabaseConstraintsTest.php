<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// These tests write through the query builder on purpose, so the database
// itself (not Eloquent or validation) has to reject the bad data.

function insertTransaction(array $overrides = []): int
{
    return DB::table('transactions')->insertGetId(array_merge([
        'user_id' => User::factory()->teller()->create()->id,
        'customer_id' => Customer::factory()->create()->id,
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

    expect(DB::table('accounting_journals')->where('transaction_id', $transactionId)->count())->toBe(2);
});

test('transaction amount must be positive', function (int $amount) {
    insertTransaction(['amount' => $amount]);
})->with([0, -5000])->throws(QueryException::class);

test('journal amount must be positive', function (int $amount) {
    insertJournal(insertTransaction(), ['amount' => $amount]);
})->with([0, -5000])->throws(QueryException::class);

test('cash transfer amount must be positive', function () {
    DB::table('cash_transfers')->insert([
        'teller_id' => User::factory()->teller()->create()->id,
        'amount' => 0,
        'status' => 'pending',
    ]);
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

test('a teller has only one daily report per date', function () {
    $teller = User::factory()->teller()->create();

    foreach (range(1, 2) as $attempt) {
        DB::table('daily_reports')->insert([
            'teller_id' => $teller->id,
            'report_date' => '2026-10-23',
            'status' => 'draft',
        ]);
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
