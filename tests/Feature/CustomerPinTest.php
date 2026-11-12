<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\WithdrawalAuthorization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/*
| The PIN is created by the customer in the app after activation, with a
| fresh Firebase sign-in as proof. Until then nothing that needs a PIN works,
| anywhere. An administrator reset removes the PIN so the customer creates a
| new one the same way.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
    $this->firebase = fakeFirebase();
    // Activated, but no PIN yet (the state right after activation).
    $this->customer = Customer::factory()->activated('uid-budi')->create([
        'nis' => '20260001',
        'account_number' => 'RPL-2026-001',
        'email' => 'budi@example.test',
        'pin' => null,
        'balance' => 100000,
    ]);
});

function setUpPin(string $idToken, string $pin = '135790', ?string $confirmation = null)
{
    return test()->postJson('/api/customer/pin/setup', [
        'id_token' => $idToken,
        'pin' => $pin,
        'pin_confirmation' => $confirmation ?? $pin,
    ]);
}

test('after activation the customer creates their PIN with a fresh Firebase sign-in', function () {
    Sanctum::actingAs($this->customer);
    $this->getJson('/api/customer/me')->assertJsonPath('data.pin_set', false);

    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'))
        ->assertCreated()
        ->assertJsonPath('message', 'PIN berhasil dibuat.');

    expect(Hash::check('135790', $this->customer->fresh()->pin))->toBeTrue();
    Sanctum::actingAs($this->customer->fresh());
    $this->getJson('/api/customer/me')->assertJsonPath('data.pin_set', true);
    $this->postJson('/api/customer/unlock', ['pin' => '135790'])->assertOk();
});

test('the Firebase sign-in must be the account bound to this customer', function () {
    Sanctum::actingAs($this->customer);

    setUpPin($this->firebase->issue('uid-someone-else', 'budi@example.test'))
        ->assertForbidden()
        ->assertJsonPath('code', 'identity_mismatch');

    setUpPin('expired-or-fake-token')->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    expect($this->customer->fresh()->pin)->toBeNull();
});

test('an existing PIN cannot be replaced through setup', function () {
    $this->customer->update(['pin' => '123456']);
    Sanctum::actingAs($this->customer);

    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'))
        ->assertConflict()
        ->assertJsonPath('code', 'pin_already_set');

    expect(Hash::check('123456', $this->customer->fresh()->pin))->toBeTrue();
});

test('the new PIN must be six digits and confirmed', function (string $pin, ?string $confirmation) {
    Sanctum::actingAs($this->customer);

    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'), $pin, $confirmation)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
})->with([
    'too short' => ['12345', null],
    'letters' => ['abcdef', null],
    'not confirmed' => ['135790', '135791'],
]);

test('PIN setup needs a customer session', function () {
    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'))->assertUnauthorized();

    actingAsStaff(UserRoles::ADMIN);
    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'))->assertForbidden();
});

test('without a PIN the app cannot unlock, change the PIN or approve, and nothing is counted', function () {
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/customer/unlock', ['pin' => '123456'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Nasabah belum membuat PIN. PIN dibuat oleh nasabah di aplikasi Bank Mini setelah akun diaktifkan.',
            'code' => 'pin_not_set',
        ]);

    $this->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '246810', 'new_pin_confirmation' => '246810'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'pin_not_set');

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);
});

test('the teller cannot withdraw for a customer without a PIN, in any mode', function () {
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller, cashFloat()); // change float, so withdrawals can be paid out
    $pending = Customer::factory()->pending()->create(['account_number' => 'RPL-2026-002', 'balance' => 100000]);

    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2026-001')->assertJsonPath('data.pin_set', false);

    // Mobile approval: activated but no PIN.
    $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 20000])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'pin_not_set');

    // Terminal PIN: neither the activated customer without a PIN nor a pending one.
    allowTerminalWithdrawals('both');
    foreach (['RPL-2026-001', 'RPL-2026-002'] as $accountNumber) {
        $this->postJson('/api/teller/withdrawals', ['account_number' => $accountNumber, 'amount' => 20000, 'pin' => '123456'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'pin_not_set');
    }

    expect(Transaction::count())->toBe(0)
        ->and(WithdrawalAuthorization::count())->toBe(0)
        ->and($pending->fresh()->pin_failed_attempts)->toBe(0);
});

test('an administrator reset removes the PIN and lifts the lock; the customer creates a new one', function () {
    $this->customer->update(['pin' => '123456', 'pin_locked_until' => now()->addMinutes(10)]);
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$this->customer->id}/pin-reset")
        ->assertOk()
        ->assertJsonPath('message', 'PIN direset. Nasabah membuat PIN baru di aplikasi Bank Mini.')
        ->assertJsonPath('data.pin_set', false);

    $customer = $this->customer->fresh();
    expect($customer->pin)->toBeNull()
        ->and($customer->pin_locked_until)->toBeNull()
        ->and($customer->pin_failed_attempts)->toBe(0);

    Sanctum::actingAs($customer);
    setUpPin($this->firebase->issue('uid-budi', 'budi@example.test'), '246810')->assertCreated();
    $this->postJson('/api/customer/unlock', ['pin' => '246810'])->assertOk();
});

test('resetting the PIN of a pending customer changes nothing', function () {
    $pending = Customer::factory()->pending()->create();
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$pending->id}/pin-reset")->assertOk();

    expect($pending->fresh()->pin)->toBeNull();
});
