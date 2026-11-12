<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Major;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    allowTerminalWithdrawals();
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $this->customer = Customer::factory()->create([
        'account_number' => 'RPL-2026-001',
        'nis' => '20260001',
        'pin' => '123456',
        'balance' => 100000,
    ]);
    $this->teller = User::factory()->teller()->create();
    openShiftFor($this->teller, cashFloat()); // change float, so withdrawals can be paid out
    Sanctum::actingAs($this->teller);
});

function withdrawWithPin(string $pin, int $amount = 20000)
{
    return test()->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => $amount, 'pin' => $pin]);
}

/*
|--------------------------------------------------------------------------
| Withdrawal PIN
|--------------------------------------------------------------------------
*/

test('a withdrawal needs the customer\'s 6-digit PIN', function () {
    $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');

    withdrawWithPin('12ab56')->assertUnprocessable()->assertJsonValidationErrors('pin');

    expect(Transaction::count())->toBe(0);
});

test('the correct PIN authorizes the withdrawal', function () {
    withdrawWithPin('123456')->assertCreated()->assertJsonPath('data.balance_after', 80000);
});

test('a wrong PIN is refused and counted, and the balance stays the same', function () {
    withdrawWithPin('000000')
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 2)
        ->assertJsonPath('message', 'PIN salah. Sisa percobaan: 2.');

    expect($this->customer->fresh()->balance)->toBe(100000)
        ->and($this->customer->fresh()->pin_failed_attempts)->toBe(1)
        ->and(Transaction::count())->toBe(0);
});

test('three wrong PINs lock withdrawals for fifteen minutes', function () {
    withdrawWithPin('000000')->assertJsonPath('remaining_attempts', 2);
    withdrawWithPin('000000')->assertJsonPath('remaining_attempts', 1);
    withdrawWithPin('000000')
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 0)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pukul 09:15'));

    // Even the correct PIN is refused while locked.
    withdrawWithPin('123456')->assertUnprocessable()->assertJsonPath('remaining_attempts', 0);

    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2026-001')
        ->assertJsonPath('data.pin_locked_until', fn ($value) => $value !== null);

    $this->travelTo(Carbon::parse('2026-10-23 09:15:01'));

    withdrawWithPin('123456')->assertCreated();
    expect($this->customer->fresh()->pin_locked_until)->toBeNull();
});

test('a correct PIN resets the wrong-attempt counter', function () {
    withdrawWithPin('000000');
    withdrawWithPin('000000');
    withdrawWithPin('123456')->assertCreated();

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);

    withdrawWithPin('000000')->assertJsonPath('remaining_attempts', 2);
});

test('deposits do not need a PIN', function () {
    $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 5000])->assertCreated();
});

test('an administrator PIN reset lifts the lock and removes the PIN until the customer creates a new one', function () {
    foreach (range(1, 3) as $attempt) {
        withdrawWithPin('000000');
    }
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$this->customer->id}/pin-reset")->assertOk();

    Sanctum::actingAs($this->teller);
    withdrawWithPin('123456')->assertUnprocessable()->assertJsonPath('code', 'pin_not_set');
    expect($this->customer->fresh()->pin_locked_until)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Customer changes their own PIN
|--------------------------------------------------------------------------
*/

test('a customer changes their PIN with the current one', function () {
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '246810', 'new_pin_confirmation' => '246810'])
        ->assertOk()
        ->assertJsonPath('message', 'PIN berhasil diubah.');

    Sanctum::actingAs($this->teller);
    withdrawWithPin('123456')->assertUnprocessable();
    withdrawWithPin('246810')->assertCreated();
});

test('changing the PIN is validated and wrong current PINs count towards the lock', function () {
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '123456', 'new_pin_confirmation' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('new_pin');

    $this->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '246810', 'new_pin_confirmation' => '999999'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('new_pin');

    $this->postJson('/api/customer/pin', ['current_pin' => '000000', 'new_pin' => '246810', 'new_pin_confirmation' => '246810'])
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 2);

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| QR code
|--------------------------------------------------------------------------
*/

test('the teller finds a customer by scanning their QR code', function () {
    $this->getJson('/api/teller/customers/lookup?identifier='.urlencode('BANKMINI:RPL-2026-001'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id);

    // Manual fallback: typed account number or NIS.
    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2026-001')->assertOk();
    $this->getJson('/api/teller/customers/lookup?identifier=20260001')->assertOk();
    $this->getJson('/api/teller/customers/lookup?identifier='.urlencode('BANKMINI:XXX-0000-000'))->assertNotFound();
});

test('admin gets the data to print a customer card with its QR code', function () {
    $this->customer->update(['major_id' => Major::factory()->create(['code' => 'RPL', 'major_name' => 'Rekayasa Perangkat Lunak'])->id]);
    actingAsStaff(UserRoles::ADMIN);

    $this->getJson("/api/admin/customers/{$this->customer->id}/card")
        ->assertOk()
        ->assertJsonPath('data.account_number', 'RPL-2026-001')
        ->assertJsonPath('data.major.code', 'RPL')
        ->assertJsonPath('data.qr_payload', 'BANKMINI:RPL-2026-001')
        ->assertJsonMissingPath('data.pin');
});

test('a customer sees their own digital card', function () {
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/customer/card')
        ->assertOk()
        ->assertJsonPath('data.qr_payload', 'BANKMINI:RPL-2026-001')
        ->assertJsonPath('data.nis', '20260001');
});

test('only administrators print cards', function (UserRoles $role) {
    actingAsStaff($role);

    $this->getJson("/api/admin/customers/{$this->customer->id}/card")->assertForbidden();
})->with([UserRoles::SUPERVISOR, UserRoles::TELLER]);
