<?php

use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\JournalService;

beforeEach(function () {
    allowTerminalWithdrawals();
    $this->customer = Customer::factory()->create(['account_number' => '1000000001', 'balance' => 50000]);
});

/**
 * A logged-in teller with today's shift open.
 */
function tellerOnShift(): App\Models\User
{
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);

    return $teller;
}

test('deposit adds to the balance and records who executed it', function () {
    $teller = tellerOnShift();

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 25000])
        ->assertCreated()
        ->assertJsonPath('data.type', 'deposit')
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.balance_before', 50000)
        ->assertJsonPath('data.balance_after', 75000)
        ->assertJsonPath('data.customer.account_number', '1000000001');

    $transaction = Transaction::sole();
    expect($this->customer->fresh()->balance)->toBe(75000)
        ->and($transaction->teller->is($teller))->toBeTrue()
        ->and($transaction->dailyReport->teller_id)->toBe($teller->id);
});

test('transactions are refused until the teller opens a shift', function () {
    actingAsStaff(UserRoles::TELLER);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Buka shift'));

    expect(Transaction::count())->toBe(0);
});

test('transactions are refused after the shift is closed', function () {
    $teller = tellerOnShift();
    closeShiftFor($teller);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'sudah ditutup'));
});

test('transactions are refused while yesterday\'s shift is still open', function () {
    $this->travelTo(now()->subDay());
    $teller = tellerOnShift();
    $this->travelBack();

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'belum ditutup'));
});

test('withdrawal subtracts from the balance', function () {
    tellerOnShift();

    $this->postJson('/api/teller/withdrawals', ['account_number' => '1000000001', 'amount' => 30000, 'pin' => '123456'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'withdrawal')
        ->assertJsonPath('data.balance_after', 20000);

    expect($this->customer->fresh()->balance)->toBe(20000);
});

test('withdrawal may leave exactly the minimum balance', function () {
    tellerOnShift();

    $this->postJson('/api/teller/withdrawals', ['account_number' => '1000000001', 'amount' => 40000, 'pin' => '123456'])->assertCreated();

    expect($this->customer->fresh()->balance)->toBe(10000);
});

test('withdrawal below the minimum balance is rejected and nothing is saved', function () {
    tellerOnShift();

    $this->postJson('/api/teller/withdrawals', ['account_number' => '1000000001', 'amount' => 40001, 'pin' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Rp40.000'));

    expect($this->customer->fresh()->balance)->toBe(50000)
        ->and(Transaction::count())->toBe(0)
        ->and(AccountingJournal::count())->toBe(0);
});

test('the minimum balance is configurable', function () {
    config(['bank.min_balance' => 0]);
    tellerOnShift();

    $this->postJson('/api/teller/withdrawals', ['account_number' => '1000000001', 'amount' => 50000, 'pin' => '123456'])->assertCreated();
});

test('zero and negative amounts are rejected', function (int $amount) {
    tellerOnShift();

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => $amount])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect(Transaction::count())->toBe(0);
})->with([0, -5000]);

test('suspended accounts cannot transact', function () {
    tellerOnShift();
    $this->customer->update(['account_status' => 'suspended']);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertUnprocessable();

    expect(Transaction::count())->toBe(0);
});

test('closed accounts cannot transact', function () {
    tellerOnShift();
    $this->customer->update(['account_status' => 'inactive']);
    $this->customer->delete();

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_number');
});

test('transactions require an authenticated teller', function () {
    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertUnauthorized();
});

test('only tellers can execute transactions', function (UserRoles $role) {
    actingAsStaff($role);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertForbidden();
    $this->postJson('/api/teller/withdrawals', ['account_number' => '1000000001', 'amount' => 10000, 'pin' => '123456'])->assertForbidden();
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR]);

test('customers cannot create transactions for themselves', function () {
    Laravel\Sanctum\Sanctum::actingAs($this->customer);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertForbidden();
});

test('if writing the journal fails, the balance and transaction are rolled back', function () {
    tellerOnShift();
    $this->mock(JournalService::class)
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('Simulated journal failure'));

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 25000])->assertServerError();

    expect($this->customer->fresh()->balance)->toBe(50000)
        ->and(Transaction::count())->toBe(0)
        ->and(AccountingJournal::count())->toBe(0);
});

test('teller sees only their own transactions for the requested day', function () {
    $teller = tellerOnShift();
    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertCreated();

    $otherTeller = App\Models\User::factory()->teller()->create();
    openShiftFor($otherTeller);
    app(App\Services\TransactionService::class)->deposit($this->customer, 5000, $otherTeller);

    $this->getJson('/api/teller/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 10000);

    $this->getJson('/api/teller/transactions?date='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('recorded transactions cannot be edited or deleted', function () {
    tellerOnShift();
    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertCreated();
    $transaction = Transaction::sole();

    expect(fn () => $transaction->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $transaction->delete())->toThrow(LogicException::class)
        ->and($transaction->fresh()->amount)->toBe(10000);
});
