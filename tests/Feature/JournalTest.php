<?php

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;

beforeEach(function () {
    $this->teller = User::factory()->teller()->create();
    openShiftFor($this->teller);
    $this->customer = Customer::factory()->create(['balance' => 100000]);
    $this->service = app(TransactionService::class);
});

test('each transaction creates exactly two opposite journal lines with the same amount', function (string $method) {
    $transaction = $method === 'withdraw'
        ? $this->service->withdraw($this->customer, 20000, $this->teller, '123456')
        : $this->service->deposit($this->customer, 20000, $this->teller);

    $journals = $transaction->accountingJournals()->get();

    expect($journals)->toHaveCount(2)
        ->and($journals->pluck('amount')->all())->toBe([20000, 20000])
        ->and($journals->pluck('position')->unique())->toHaveCount(2)
        ->and($journals->every(fn ($journal) => $journal->account_code instanceof AccountCode))->toBeTrue();
})->with(['deposit', 'withdraw']);

test('a deposit debits cash and credits customer savings', function () {
    $transaction = $this->service->deposit($this->customer, 20000, $this->teller);

    $debit = $transaction->accountingJournals()->where('position', Position::DEBIT)->sole();
    $credit = $transaction->accountingJournals()->where('position', Position::CREDIT)->sole();

    expect($debit->account_code)->toBe(AccountCode::CASH)
        ->and($credit->account_code)->toBe(AccountCode::CUSTOMER_SAVINGS);
});

test('a withdrawal debits customer savings and credits cash', function () {
    $transaction = $this->service->withdraw($this->customer, 20000, $this->teller, '123456');

    $debit = $transaction->accountingJournals()->where('position', Position::DEBIT)->sole();
    $credit = $transaction->accountingJournals()->where('position', Position::CREDIT)->sole();

    expect($debit->account_code)->toBe(AccountCode::CUSTOMER_SAVINGS)
        ->and($credit->account_code)->toBe(AccountCode::CASH);
});

test('admin and supervisor can read the journal', function (UserRoles $role) {
    $this->service->deposit($this->customer, 20000, $this->teller);
    actingAsStaff($role);

    $this->getJson('/api/audit/journals')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'transaction_id', 'account_code', 'account_name', 'position', 'amount']], 'meta', 'links']);
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR]);

test('tellers and customers cannot read the journal', function () {
    actingAsStaff(UserRoles::TELLER);
    $this->getJson('/api/audit/journals')->assertForbidden();

    actingAsCustomer();
    $this->getJson('/api/audit/journals')->assertForbidden();
});

test('the journal can be filtered by transaction, account, position and date', function () {
    $first = $this->service->deposit($this->customer, 20000, $this->teller);
    $this->service->withdraw($this->customer, 5000, $this->teller, '123456');
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson("/api/audit/journals?transaction_id={$first->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/audit/journals?account_code=101')->assertJsonCount(2, 'data');
    $this->getJson('/api/audit/journals?account_code=101&position=debit')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 20000);
    $this->getJson('/api/audit/journals?from='.now()->toDateString())->assertJsonCount(4, 'data');
    $this->getJson('/api/audit/journals?to='.now()->subDay()->toDateString())->assertJsonCount(0, 'data');
});

test('the journal summary is balanced and totals each account', function () {
    $this->service->deposit($this->customer, 20000, $this->teller);
    $this->service->withdraw($this->customer, 5000, $this->teller, '123456');
    actingAsStaff(UserRoles::ADMIN);

    $this->getJson('/api/audit/journals/summary')
        ->assertOk()
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonPath('data.total_debit', 25000)
        ->assertJsonPath('data.total_credit', 25000)
        ->assertJsonPath('data.accounts.0.account_code', '101')
        ->assertJsonPath('data.accounts.0.total_debit', 20000)
        ->assertJsonPath('data.accounts.0.total_credit', 5000)
        ->assertJsonPath('data.accounts.1.account_code', '201')
        ->assertJsonPath('data.accounts.1.total_debit', 5000)
        ->assertJsonPath('data.accounts.1.total_credit', 20000);
});

test('the journal has no write endpoints', function () {
    $transaction = $this->service->deposit($this->customer, 20000, $this->teller);
    $journal = AccountingJournal::first();
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson('/api/audit/journals', [])->assertMethodNotAllowed();
    $this->putJson("/api/audit/journals/{$journal->id}", ['amount' => 1])->assertNotFound();
    $this->deleteJson("/api/audit/journals/{$journal->id}")->assertNotFound();
    $this->deleteJson("/api/audit/transactions/{$transaction->id}")->assertMethodNotAllowed();

    expect(AccountingJournal::count())->toBe(2);
});

test('journal lines cannot be edited or deleted through the model', function () {
    $this->service->deposit($this->customer, 20000, $this->teller);
    $journal = AccountingJournal::first();

    expect(fn () => $journal->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $journal->delete())->toThrow(LogicException::class);
});

test('the audit transaction log shows details with journals, including closed accounts', function () {
    $transaction = $this->service->deposit($this->customer, 20000, $this->teller);
    $this->customer->delete();
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/audit/transactions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.customer.id', $this->customer->id)
        ->assertJsonPath('data.0.teller.id', $this->teller->id);

    $this->getJson("/api/audit/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.journals');

    $this->getJson('/api/audit/transactions?type=withdrawal')->assertJsonCount(0, 'data');
    $this->getJson("/api/audit/transactions?teller_id={$this->teller->id}")->assertJsonCount(1, 'data');

    expect(Transaction::count())->toBe(1);
});
