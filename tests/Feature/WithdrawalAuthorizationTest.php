<?php

use App\Enums\TransactionTypes;
use App\Enums\UserRoles;
use App\Enums\WithdrawalAuthorizationStatus;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalAuthorization;
use App\Services\JournalService;
use App\Services\TransactionService;
use App\Services\WithdrawalAuthorizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Mode A: the teller starts a withdrawal, the customer approves it in the
| mobile app with their PIN, the teller completes it. The PIN never passes
| through the teller.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $this->customer = Customer::factory()->activated()->create([
        'account_number' => 'RPL-2026-001',
        'nis' => '20260001',
        'pin' => '123456',
        'balance' => 100000,
    ]);
    $this->teller = User::factory()->teller()->create();
    $this->shift = openShiftFor($this->teller);
    Sanctum::actingAs($this->teller);
});

/**
 * As the teller: ask the customer to approve a withdrawal. Returns the response.
 */
function requestWithdrawal(int $amount = 20000, string $accountNumber = 'RPL-2026-001')
{
    return test()->postJson('/api/teller/withdrawal-authorizations', ['account_number' => $accountNumber, 'amount' => $amount]);
}

/**
 * As the teller: create a request and return its public id.
 */
function pendingWithdrawal(int $amount = 20000): string
{
    return requestWithdrawal($amount)->assertCreated()->json('data.id');
}

/**
 * As the customer: approve with the PIN. Switches the acting user.
 */
function approveAsCustomer(string $id, string $pin = '123456', ?Customer $customer = null)
{
    Sanctum::actingAs($customer ?? test()->customer);

    return test()->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => $pin]);
}

function asTeller(?User $teller = null): void
{
    Sanctum::actingAs($teller ?? test()->teller);
}

function authorization(string $id): WithdrawalAuthorization
{
    return WithdrawalAuthorization::where('public_id', $id)->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

test('teller requests, customer approves with PIN, teller completes the withdrawal', function () {
    $response = requestWithdrawal(20000)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.customer.account_number', 'RPL-2026-001')
        ->assertJsonPath('data.expires_at', fn ($value) => Carbon::parse($value)->equalTo(now()->addSeconds(120)));
    $id = $response->json('data.id');

    // No money moves until the teller completes it.
    expect($this->customer->fresh()->balance)->toBe(100000)
        ->and(Transaction::count())->toBe(0);

    Sanctum::actingAs($this->customer);
    $this->getJson('/api/customer/withdrawal-authorizations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.balance', 100000)
        ->assertJsonPath('data.0.balance_after', 80000)
        ->assertJsonPath('data.0.teller.full_name', $this->teller->full_name)
        ->assertJsonPath('data.0.amount_in_words', fn ($words) => str_contains(strtolower($words), 'dua puluh ribu'));

    approveAsCustomer($id)
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approval_expires_at', fn ($value) => Carbon::parse($value)->equalTo(now()->addSeconds(120)));

    asTeller();
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertOk()->assertJsonPath('data.status', 'approved');

    $transaction = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")
        ->assertCreated()
        ->assertJsonPath('data.type', TransactionTypes::WITHDRAWAL->value)
        ->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.balance_after', 80000)
        ->assertJsonPath('data.withdrawal_authorization.id', $id)
        ->assertJsonPath('data.withdrawal_authorization.method', 'mobile')
        ->json('data');

    $authorization = authorization($id);
    expect($this->customer->fresh()->balance)->toBe(80000)
        ->and($authorization->status)->toBe(WithdrawalAuthorizationStatus::CONSUMED)
        ->and($authorization->transaction_id)->toBe($transaction['id'])
        ->and($authorization->consumed_at)->not->toBeNull()
        ->and(AccountingJournal::where('transaction_id', $transaction['id'])->count())->toBeGreaterThan(0);
});

test('completing twice returns the same transaction and takes the money once', function () {
    $id = pendingWithdrawal(20000);
    approveAsCustomer($id)->assertOk();
    asTeller();

    $first = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertCreated()->json('data.id');
    $second = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(Transaction::count())->toBe(1)
        ->and($this->customer->fresh()->balance)->toBe(80000);
});

test('the approval cannot be completed before the customer approves', function () {
    $id = pendingWithdrawal();

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")
        ->assertUnprocessable()
        ->assertJsonPath('status', 'pending');

    expect(Transaction::count())->toBe(0);
});

test('the transaction detail in the audit trail shows the mobile approval', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    asTeller();
    $transactionId = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->json('data.id');

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->getJson("/api/audit/transactions/{$transactionId}")
        ->assertOk()
        ->assertJsonPath('data.withdrawal_authorization.id', $id)
        ->assertJsonPath('data.withdrawal_authorization.method', 'mobile');
});

/*
|--------------------------------------------------------------------------
| Reject, cancel, expiry
|--------------------------------------------------------------------------
*/

test('the customer can reject the request and it can no longer be used', function () {
    $id = pendingWithdrawal();

    Sanctum::actingAs($this->customer);
    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    approveAsCustomer($id)->assertUnprocessable()->assertJsonPath('status', 'rejected');

    asTeller();
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertUnprocessable();

    expect(Transaction::count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(100000);
});

test('an approved request can no longer be rejected', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id)->assertOk();

    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/reject")
        ->assertUnprocessable()
        ->assertJsonPath('status', 'approved');
});

test('the teller can cancel a pending or approved request', function () {
    $id = pendingWithdrawal();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'teller');
    approveAsCustomer($id)->assertUnprocessable()->assertJsonPath('status', 'cancelled');

    asTeller();
    $id = pendingWithdrawal();
    approveAsCustomer($id)->assertOk();
    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertUnprocessable();

    expect(Transaction::count())->toBe(0);
});

test('a consumed request cannot be cancelled', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertCreated();

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('status', 'consumed');
});

test('a pending request expires after 120 seconds', function () {
    $id = pendingWithdrawal();

    $this->travel(119)->seconds();
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertJsonPath('data.status', 'pending');

    $this->travel(1)->seconds();
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertJsonPath('data.status', 'expired');

    approveAsCustomer($id)->assertUnprocessable()->assertJsonPath('status', 'expired');
    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);

    // Expired requests disappear from the customer's list.
    $this->getJson('/api/customer/withdrawal-authorizations')->assertJsonCount(0, 'data');
});

test('an approval expires if the teller does not complete it within 120 seconds', function () {
    $id = pendingWithdrawal();
    $this->travel(100)->seconds();
    approveAsCustomer($id)->assertOk();

    // The approval window starts at approval, not at creation.
    $this->travel(119)->seconds();
    asTeller();
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertJsonPath('data.status', 'approved');

    $this->travel(1)->seconds();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertUnprocessable();

    expect(Transaction::count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(100000);
    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertJsonPath('data.status', 'expired');
});

test('the scheduled sweep marks abandoned requests as expired', function () {
    $id = pendingWithdrawal();
    $this->travel(121)->seconds();

    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING);

    $this->artisan('bank:expire-withdrawal-authorizations')
        ->expectsOutput('Expired: 1')
        ->assertSuccessful();

    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::EXPIRED);
});

test('closing the shift cancels its open requests', function () {
    $pending = pendingWithdrawal();

    closeShiftFor($this->teller);

    expect(authorization($pending)->status)->toBe(WithdrawalAuthorizationStatus::CANCELLED)
        ->and(authorization($pending)->cancel_reason)->toBe('shift_closed');

    approveAsCustomer($pending)->assertUnprocessable()->assertJsonPath('status', 'cancelled');
});

test('an approved request cannot be completed after the shift is closed', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id)->assertOk();

    closeShiftFor($this->teller);

    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertUnprocessable();

    expect(Transaction::count())->toBe(0)
        ->and(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::CANCELLED);
});

/*
|--------------------------------------------------------------------------
| PIN
|--------------------------------------------------------------------------
*/

test('approval needs a 6-digit PIN', function () {
    $id = pendingWithdrawal();
    Sanctum::actingAs($this->customer);

    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => '12ab56'])
        ->assertJsonValidationErrors('pin');

    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING);
});

test('a wrong PIN is refused, counted, and leaves the request pending', function () {
    $id = pendingWithdrawal();

    approveAsCustomer($id, '000000')
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 2);

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(1)
        ->and(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING);

    // A correct PIN afterwards still works and resets the counter.
    approveAsCustomer($id)->assertOk();
    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);
});

test('three wrong PINs lock approval, unlock and new requests for fifteen minutes', function () {
    $id = pendingWithdrawal();

    approveAsCustomer($id, '000000')->assertJsonPath('remaining_attempts', 2);
    approveAsCustomer($id, '000000')->assertJsonPath('remaining_attempts', 1);
    approveAsCustomer($id, '000000')
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 0)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pukul 09:15'));

    // Even the correct PIN is refused while locked.
    approveAsCustomer($id)->assertUnprocessable()->assertJsonPath('remaining_attempts', 0);
    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING);

    // The same lock applies to unlocking the app.
    $this->postJson('/api/customer/unlock', ['pin' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pukul 09:15'));

    // And the teller cannot start a new request while the PIN is locked.
    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel")->assertOk();
    requestWithdrawal()->assertUnprocessable()->assertJsonPath('remaining_attempts', 0);
});

test('the PIN never appears in any response', function () {
    $responses = [];
    $responses[] = requestWithdrawal();
    $id = $responses[0]->json('data.id');
    $responses[] = $this->getJson("/api/teller/withdrawal-authorizations/{$id}");
    $responses[] = $this->getJson('/api/teller/withdrawal-authorizations');

    Sanctum::actingAs($this->customer);
    $responses[] = $this->getJson('/api/customer/withdrawal-authorizations');
    $responses[] = $this->getJson("/api/customer/withdrawal-authorizations/{$id}");
    $responses[] = approveAsCustomer($id);

    asTeller();
    $responses[] = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete");

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('"pin')
            ->and($response->getContent())->not->toContain('123456');
    }
});

/*
|--------------------------------------------------------------------------
| One open request at a time
|--------------------------------------------------------------------------
*/

test('a customer can have only one open request', function () {
    pendingWithdrawal();

    $otherTeller = User::factory()->teller()->create();
    openShiftFor($otherTeller);
    asTeller($otherTeller);

    requestWithdrawal()
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'belum selesai'));

    expect(WithdrawalAuthorization::count())->toBe(1);
});

test('a teller can have only one open request', function () {
    pendingWithdrawal();
    Customer::factory()->activated()->create(['account_number' => 'RPL-2026-002', 'balance' => 100000]);

    requestWithdrawal(20000, 'RPL-2026-002')->assertUnprocessable();

    expect(WithdrawalAuthorization::count())->toBe(1);
});

test('a new request is allowed once the previous one is finished or expired', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertCreated();

    $second = pendingWithdrawal();
    $this->travel(121)->seconds();

    pendingWithdrawal();

    expect(authorization($second)->status)->toBe(WithdrawalAuthorizationStatus::EXPIRED);
});

test('approving twice is refused the second time', function () {
    $id = pendingWithdrawal();

    approveAsCustomer($id)->assertOk();
    approveAsCustomer($id)->assertUnprocessable()->assertJsonPath('status', 'approved');
});

test('?status=open returns the teller\'s request in progress', function () {
    $done = pendingWithdrawal();
    $this->postJson("/api/teller/withdrawal-authorizations/{$done}/cancel");
    $open = pendingWithdrawal();

    $this->getJson('/api/teller/withdrawal-authorizations?status=open')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open);

    $this->getJson('/api/teller/withdrawal-authorizations')->assertJsonCount(2, 'data');
});

/*
|--------------------------------------------------------------------------
| Ownership and access
|--------------------------------------------------------------------------
*/

test('other tellers cannot see, cancel or complete the request', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);

    $otherTeller = User::factory()->teller()->create();
    openShiftFor($otherTeller);
    asTeller($otherTeller);

    $this->getJson("/api/teller/withdrawal-authorizations/{$id}")->assertNotFound();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel")->assertNotFound();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertNotFound();
    $this->getJson('/api/teller/withdrawal-authorizations')->assertJsonCount(0, 'data');

    expect(Transaction::count())->toBe(0);
});

test('other customers cannot see, approve or reject the request', function () {
    $id = pendingWithdrawal();
    $stranger = Customer::factory()->create(['pin' => '123456']);

    Sanctum::actingAs($stranger);
    $this->getJson('/api/customer/withdrawal-authorizations')->assertJsonCount(0, 'data');
    $this->getJson("/api/customer/withdrawal-authorizations/{$id}")->assertNotFound();
    approveAsCustomer($id, '123456', $stranger)->assertNotFound();
    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/reject")->assertNotFound();

    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING)
        ->and($stranger->fresh()->pin_failed_attempts)->toBe(0);
});

test('staff cannot approve and customers cannot create requests', function () {
    $id = pendingWithdrawal();

    foreach ([UserRoles::TELLER, UserRoles::SUPERVISOR, UserRoles::ADMIN] as $role) {
        actingAsStaff($role);
        $this->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => '123456'])->assertForbidden();
    }

    Sanctum::actingAs($this->customer);
    $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 10000])->assertForbidden();
    $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 10000, 'pin' => '123456'])->assertForbidden();

    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING)
        ->and(WithdrawalAuthorization::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Business rules
|--------------------------------------------------------------------------
*/

test('the request needs an open shift and a valid amount', function () {
    requestWithdrawal(0)->assertUnprocessable()->assertJsonValidationErrors('amount');
    requestWithdrawal(20000, 'NOPE-0000')->assertUnprocessable();

    $noShift = User::factory()->teller()->create();
    asTeller($noShift);
    requestWithdrawal()->assertUnprocessable();

    expect(WithdrawalAuthorization::count())->toBe(0);
});

test('a request that would break the minimum balance is refused up front', function () {
    // Balance 100 000, minimum 10 000: at most 90 000.
    requestWithdrawal(90001)->assertUnprocessable();
    requestWithdrawal(90000)->assertCreated();
});

test('the balance is checked again when completing', function () {
    $id = pendingWithdrawal(80000);
    approveAsCustomer($id)->assertOk();

    // Another withdrawal at the terminal meanwhile.
    allowTerminalWithdrawals('both');
    $otherTeller = User::factory()->teller()->create();
    openShiftFor($otherTeller);
    asTeller($otherTeller);
    $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 50000, 'pin' => '123456'])->assertCreated();

    asTeller();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertUnprocessable();

    expect($this->customer->fresh()->balance)->toBe(50000)
        ->and(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::APPROVED)
        ->and(Transaction::count())->toBe(1);
});

test('requests are refused for accounts that are not active', function () {
    Customer::factory()->suspended()->activated()->create(['account_number' => 'RPL-2026-009', 'balance' => 100000]);

    requestWithdrawal(20000, 'RPL-2026-009')->assertUnprocessable();
    expect(WithdrawalAuthorization::count())->toBe(0);

    $id = pendingWithdrawal();
    $this->customer->update(['account_status' => 'suspended']);

    approveAsCustomer($id, '123456', $this->customer->fresh())->assertUnprocessable();
    expect(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::PENDING);
});

test('if writing the journal fails, nothing is withdrawn and the approval can be retried', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    asTeller();

    $this->mock(JournalService::class)
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('Simulated journal failure'));

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertServerError();

    expect($this->customer->fresh()->balance)->toBe(100000)
        ->and(Transaction::count())->toBe(0)
        ->and(authorization($id)->status)->toBe(WithdrawalAuthorizationStatus::APPROVED)
        ->and(authorization($id)->transaction_id)->toBeNull();

    $this->app->forgetInstance(JournalService::class);
    $this->app->forgetInstance(TransactionService::class);

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->assertCreated();
    expect($this->customer->fresh()->balance)->toBe(80000);
});

test('a stale copy of a cancelled request cannot be completed', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    $stale = authorization($id);

    app(WithdrawalAuthorizationService::class)->cancel($stale);

    expect(fn () => app(TransactionService::class)->completeAuthorizedWithdrawal($stale, $this->teller))
        ->toThrow(App\Exceptions\AuthorizationNotUsableException::class);
    expect(Transaction::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Verification mode setting
|--------------------------------------------------------------------------
*/

test('by default withdrawals need mobile approval and the terminal PIN is refused', function () {
    $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'aplikasi'));

    expect(Transaction::count())->toBe(0)
        ->and($this->customer->fresh()->pin_failed_attempts)->toBe(0);
});

test('in terminal mode mobile approval requests are refused', function () {
    allowTerminalWithdrawals('terminal');

    requestWithdrawal()->assertUnprocessable();
    expect(WithdrawalAuthorization::count())->toBe(0);
});

test('in both mode either way works', function () {
    allowTerminalWithdrawals('both');

    requestWithdrawal()->assertCreated();
    $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'])->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Customers who have not activated the app
|--------------------------------------------------------------------------
*/

test('the teller lookup shows whether the customer has activated the app and created a PIN', function () {
    Customer::factory()->pending()->create(['account_number' => 'RPL-2026-002']);

    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2026-001')->assertOk()
        ->assertJsonPath('data.activation_status', 'activated')
        ->assertJsonPath('data.pin_set', true);
    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2026-002')->assertOk()
        ->assertJsonPath('data.activation_status', 'pending')
        ->assertJsonPath('data.pin_set', false);
});

test('a request for a customer without the app is refused up front', function (string $mode) {
    allowTerminalWithdrawals($mode);
    $pending = Customer::factory()->pending()->create(['account_number' => 'RPL-2026-002', 'balance' => 100000]);

    requestWithdrawal(20000, 'RPL-2026-002')
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga belum dapat melakukan penarikan. Minta nasabah mengaktifkan akun di aplikasi dan membuat PIN terlebih dahulu.',
            'code' => 'customer_app_not_activated',
        ]);

    expect(WithdrawalAuthorization::count())->toBe(0)
        ->and($pending->fresh()->balance)->toBe(100000);

    // The teller is not left with an open request and can serve the next customer.
    pendingWithdrawal();
})->with(['mobile', 'both']);

test('an inactive account is reported before the missing activation', function () {
    Customer::factory()->suspended()->pending()->create(['account_number' => 'RPL-2026-002', 'balance' => 100000]);

    requestWithdrawal(20000, 'RPL-2026-002')
        ->assertUnprocessable()
        ->assertJsonMissingPath('code');
});

/*
|--------------------------------------------------------------------------
| Push notification (FCM)
|--------------------------------------------------------------------------
*/

test('a new request is pushed to the customer once the teller has the answer', function () {
    $push = fakePush();
    $this->teller->update(['full_name' => 'Teller Loket 1']);

    $id = requestWithdrawal(50000)->assertCreated()->json('data.id');

    expect($push->sent)->toHaveCount(1)
        ->and($push->sent[0])->toBe([
            'customer_id' => $this->customer->id,
            'title' => 'Permintaan penarikan',
            'body' => 'Teller Teller Loket 1 meminta persetujuan penarikan Rp50.000. Buka aplikasi untuk menyetujui atau menolak.',
            'data' => ['type' => 'withdrawal_authorization', 'id' => $id],
        ]);
});

test('a refused request sends no push', function () {
    $push = fakePush();

    requestWithdrawal(999999999)->assertUnprocessable();

    expect($push->sent)->toBe([]);
});

test('a failing push never fails the teller\'s request', function () {
    $push = fakePush();
    $push->failing = true;

    requestWithdrawal()->assertCreated();

    expect(WithdrawalAuthorization::count())->toBe(1);
})->throwsNoExceptions();

/*
|--------------------------------------------------------------------------
| Model and database guards
|--------------------------------------------------------------------------
*/

test('finished requests are immutable and requests are never deleted', function () {
    $id = pendingWithdrawal();
    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/cancel");
    $authorization = authorization($id);

    expect(fn () => $authorization->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $authorization->delete())->toThrow(LogicException::class);
});

test('the database rejects impossible authorization rows', function (array $attributes) {
    WithdrawalAuthorization::create([
        'teller_id' => $this->teller->id,
        'customer_id' => $this->customer->id,
        'daily_report_id' => $this->shift->id,
        'amount' => 10000,
        'status' => WithdrawalAuthorizationStatus::PENDING,
        'expires_at' => now()->addMinutes(2),
        ...$attributes,
    ]);
})->with([
    'zero amount' => [['amount' => 0]],
    'consumed without a transaction' => [['status' => WithdrawalAuthorizationStatus::CONSUMED, 'approved_at' => now(), 'approval_expires_at' => now(), 'consumed_at' => now()]],
    'approved without approval times' => [['status' => WithdrawalAuthorizationStatus::APPROVED]],
])->throws(QueryException::class);

test('one transaction can satisfy only one authorization', function () {
    $id = pendingWithdrawal();
    approveAsCustomer($id);
    asTeller();
    $transactionId = $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete")->json('data.id');

    WithdrawalAuthorization::create([
        'teller_id' => $this->teller->id,
        'customer_id' => $this->customer->id,
        'daily_report_id' => $this->shift->id,
        'amount' => 20000,
        'status' => WithdrawalAuthorizationStatus::CONSUMED,
        'expires_at' => now(),
        'approved_at' => now(),
        'approval_expires_at' => now(),
        'consumed_at' => now(),
        'transaction_id' => $transactionId,
    ]);
})->throws(QueryException::class);
