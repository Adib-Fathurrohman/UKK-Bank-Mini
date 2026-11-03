<?php

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Customer login checks the PIN through PinService, so wrong PINs at login
| share the lock with withdrawal approval and PIN change (3 wrong in a row
| lock the PIN for 15 minutes). Login never reveals the remaining attempts.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => 'RPL-2026-001',
        'pin' => '123456',
    ]);
});

function customerLogin(string $pin, string $identifier = '20260001')
{
    return test()->postJson('/api/customer/login', ['identifier' => $identifier, 'pin' => $pin]);
}

test('a wrong PIN at login is counted but the remaining attempts are not revealed', function () {
    foreach ([1, 2] as $attempt) {
        customerLogin('000000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.'])
            ->assertJsonMissingPath('remaining_attempts');

        expect($this->customer->fresh()->pin_failed_attempts)->toBe($attempt);
    }
});

test('the third wrong PIN locks login for fifteen minutes', function () {
    customerLogin('000000');
    customerLogin('000000');
    customerLogin('000000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier' => 'Terlalu banyak percobaan PIN salah. Coba lagi pukul 09:15.']);

    // The correct PIN is refused while locked, and does not unlock it.
    customerLogin('123456')->assertUnprocessable()->assertJsonValidationErrors(['identifier' => 'pukul 09:15']);
    expect($this->customer->fresh()->pin_locked_until)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-23 09:15:01'));

    customerLogin('123456')->assertOk();
    expect($this->customer->fresh())
        ->pin_failed_attempts->toBe(0)
        ->pin_locked_until->toBeNull();
});

test('a successful login resets the wrong attempt counter', function () {
    customerLogin('000000');
    customerLogin('000000');

    customerLogin('123456')->assertOk();

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);
});

test('switching between NIS and account number does not reset the count', function () {
    customerLogin('000000', '20260001');
    customerLogin('000000', 'RPL-2026-001');
    customerLogin('000000', '20260001')->assertJsonValidationErrors(['identifier' => 'pukul 09:15']);

    customerLogin('123456', 'RPL-2026-001')->assertUnprocessable();
});

test('wrong PINs through the login gateway count too', function () {
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/login', ['identifier' => 'RPL-2026-001', 'pin' => '000000'])->assertUnprocessable();
    }

    expect($this->customer->fresh()->pin_locked_until)->not->toBeNull();
});

test('an unknown identifier gets the same message and changes nothing', function () {
    customerLogin('000000', '99999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.']);

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(0);
});

test('a lock from login also blocks PIN change', function () {
    Sanctum::actingAs($this->customer);

    foreach (range(1, 3) as $attempt) {
        customerLogin('000000');
    }

    $this->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '246810', 'new_pin_confirmation' => '246810'])
        ->assertUnprocessable();

    $this->customer->refresh();
    expect(Illuminate\Support\Facades\Hash::check('123456', $this->customer->pin))->toBeTrue();
});

test('an inactive account is reported only after the correct PIN', function () {
    $this->customer->update(['account_status' => 'inactive']);

    customerLogin('000000')->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.']);
    customerLogin('123456')->assertJsonValidationErrors(['identifier' => 'Rekening ini sudah tidak aktif.']);
});
