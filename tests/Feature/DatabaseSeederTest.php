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

test('demo accounts can log in', function () {
    $this->seed();

    $this->postJson('/api/staff/login', ['username' => 'admin', 'password' => 'password'])->assertOk();
    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])->assertOk();
});
