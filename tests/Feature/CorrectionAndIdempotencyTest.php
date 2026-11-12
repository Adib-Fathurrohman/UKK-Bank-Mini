<?php

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Enums\TransactionTypes;
use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    allowTerminalWithdrawals();
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $this->teller = User::factory()->teller()->create();
    $this->supervisor = User::factory()->supervisor()->create(['username' => 'spv', 'password' => 'rahasia123']);
    $this->customer = Customer::factory()->create(['account_number' => 'RPL-2026-001', 'balance' => 100000]);
    $this->transactions = app(TransactionService::class);
    openShiftFor($this->teller, cashFloat()); // change float, so withdrawals can be paid out
    Sanctum::actingAs($this->teller);
});

function correctionBody(array $overrides = []): array
{
    return ['reason' => 'Salah ketik nominal', 'supervisor_username' => 'spv', 'supervisor_password' => 'rahasia123', ...$overrides];
}

/*
|--------------------------------------------------------------------------
| Corrections
|--------------------------------------------------------------------------
*/

test('a deposit typed in error is reversed with a supervisor\'s authorization', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())
        ->assertCreated()
        ->assertJsonPath('data.type', 'deposit_correction')
        ->assertJsonPath('data.type_label', 'Koreksi setoran')
        ->assertJsonPath('data.amount', 50000)
        ->assertJsonPath('data.balance_before', 150000)
        ->assertJsonPath('data.balance_after', 100000)
        ->assertJsonPath('data.corrects_transaction_id', $deposit->id)
        ->assertJsonPath('data.authorized_by.id', $this->supervisor->id)
        ->assertJsonPath('data.note', 'Salah ketik nominal');

    $correction = Transaction::where('corrects_transaction_id', $deposit->id)->sole();
    $journals = $correction->accountingJournals()->get();

    expect($this->customer->fresh()->balance)->toBe(100000)
        ->and($journals->firstWhere('position', Position::DEBIT)->account_code)->toBe(AccountCode::CUSTOMER_SAVINGS)
        ->and($journals->firstWhere('position', Position::CREDIT)->account_code)->toBe(AccountCode::CASH);

    $this->getJson('/api/teller/transactions')
        ->assertJsonPath('data.1.id', $deposit->id)
        ->assertJsonPath('data.1.corrected_by.id', $correction->id);
});

test('a withdrawal typed in error is reversed and the money goes back', function () {
    $withdrawal = $this->transactions->withdraw($this->customer, 30000, $this->teller, '123456');

    $this->postJson("/api/teller/transactions/{$withdrawal->id}/correct", correctionBody())
        ->assertCreated()
        ->assertJsonPath('data.type', 'withdrawal_correction')
        ->assertJsonPath('data.balance_after', 100000);

    $journals = Transaction::where('corrects_transaction_id', $withdrawal->id)->sole()->accountingJournals()->get();
    expect($journals->firstWhere('position', Position::DEBIT)->account_code)->toBe(AccountCode::CASH);
});

test('the supervisor authorization is checked', function (array $credentials) {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    User::factory()->teller()->create(['username' => 'teller2', 'password' => 'rahasia123']);
    User::factory()->supervisor()->inactive()->create(['username' => 'spv_blocked', 'password' => 'rahasia123']);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody($credentials))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supervisor_password');

    expect(Transaction::count())->toBe(1);
})->with([
    'wrong password' => [['supervisor_password' => 'salah']],
    'not a supervisor' => [['supervisor_username' => 'teller2']],
    'blocked supervisor' => [['supervisor_username' => 'spv_blocked']],
    'unknown user' => [['supervisor_username' => 'nobody']],
]);

test('a correction needs a reason', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody(['reason' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

test('a transaction can be corrected only once, and corrections cannot be corrected', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    $correction = $this->transactions->correct($deposit, $this->teller, $this->supervisor, 'Salah');

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Transaksi ini sudah pernah dikoreksi.');

    $this->postJson("/api/teller/transactions/{$correction->id}/correct", correctionBody())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Transaksi koreksi tidak dapat dikoreksi lagi.');
});

test('tellers cannot correct each other\'s transactions', function () {
    $other = User::factory()->teller()->create();
    openShiftFor($other, cashFloat()); // change float, so withdrawals can be paid out
    $deposit = $this->transactions->deposit($this->customer, 50000, $other);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertNotFound();
});

test('corrections are only possible while the shift of the original is running', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    $report = closeShiftFor($this->teller);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'shift'));

    // A supervisor rejection reopens the shift, so the teller can correct it then.
    app(DailyReportService::class)->reject($report, $this->supervisor, 'Ada salah ketik');

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertCreated();
});

test('transactions from an earlier shift cannot be corrected in today\'s shift', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    closeShiftFor($this->teller);
    $this->travelTo(Carbon::parse('2026-10-24 09:00:00'));
    openShiftFor($this->teller);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertUnprocessable();
});

test('reversing a deposit cannot take the balance below zero', function () {
    $empty = Customer::factory()->create(['balance' => 0]);
    $deposit = $this->transactions->deposit($empty, 50000, $this->teller);
    $this->transactions->withdraw($empty, 40000, $this->teller, '123456');

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'tidak mencukupi'));

    expect($empty->fresh()->balance)->toBe(10000);
});

test('shift totals are net of corrections, so the drawer still reconciles', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    $this->transactions->correct($deposit, $this->teller, $this->supervisor, 'Salah');
    $reports = app(DailyReportService::class);

    expect($reports->summarize($reports->activeShift($this->teller)))->toMatchArray([
        'transaction_count' => 2,
        'total_deposit' => 0,
        'system_balance' => 980000,
    ]);

    // The deposit's notes went back out, so the drawer is the opening float again.
    $this->postJson('/api/teller/shift/close', ['denominations' => cashFloat()])->assertOk();
});

test('the customer statement shows the correction', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    $this->transactions->correct($deposit, $this->teller, $this->supervisor, 'Salah');
    Sanctum::actingAs($this->customer->fresh());

    $this->getJson('/api/customer/transactions')
        ->assertJsonPath('data.0.description', 'Koreksi setoran')
        ->assertJsonPath('data.0.direction', 'out')
        ->assertJsonPath('data.0.balance_after', 100000);
});

test('only tellers can record corrections', function (UserRoles $role) {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    actingAsStaff($role);

    $this->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertForbidden();
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR]);

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

test('repeating a deposit with the same Idempotency-Key records it once', function () {
    $body = ['account_number' => 'RPL-2026-001', 'amount' => 25000];

    $first = $this->withHeader('Idempotency-Key', 'loket1-0001')->postJson('/api/teller/deposits', $body)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', 'loket1-0001')->postJson('/api/teller/deposits', $body)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Transaction::count())->toBe(1)
        ->and(AccountingJournal::count())->toBe(2)
        ->and($this->customer->fresh()->balance)->toBe(125000);
});

test('a key reused for a different transaction is refused', function () {
    $this->withHeader('Idempotency-Key', 'loket1-0002')
        ->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 25000])
        ->assertCreated();

    $this->withHeader('Idempotency-Key', 'loket1-0002')
        ->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 30000])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Idempotency-Key'));

    expect(Transaction::count())->toBe(1);
});

test('idempotency keys are per teller', function () {
    $other = User::factory()->teller()->create();
    openShiftFor($other, cashFloat()); // change float, so withdrawals can be paid out

    $this->transactions->deposit($this->customer, 25000, $this->teller, 'same-key');
    $this->transactions->deposit($this->customer, 25000, $other, 'same-key');

    expect(Transaction::count())->toBe(2);
});

test('a repeated withdrawal is not taken twice and does not re-check the PIN', function () {
    $body = ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'];

    $this->withHeader('Idempotency-Key', 'loket1-0003')->postJson('/api/teller/withdrawals', $body)->assertCreated();
    $this->customer->update(['pin_locked_until' => now()->addMinutes(15)]);
    $this->withHeader('Idempotency-Key', 'loket1-0003')->postJson('/api/teller/withdrawals', $body)->assertOk();

    expect(Transaction::where('type', TransactionTypes::WITHDRAWAL)->count())->toBe(1)
        ->and($this->customer->fresh()->balance)->toBe(80000);
});

test('a repeated correction is recorded once', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);

    $this->withHeader('Idempotency-Key', 'koreksi-1')->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertCreated();
    $this->withHeader('Idempotency-Key', 'koreksi-1')->postJson("/api/teller/transactions/{$deposit->id}/correct", correctionBody())->assertOk();

    expect(Transaction::count())->toBe(2);
});

test('malformed idempotency keys are rejected', function () {
    $this->withHeader('Idempotency-Key', 'not a valid key!')
        ->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 25000])
        ->assertUnprocessable();

    expect(Transaction::count())->toBe(0);
});
