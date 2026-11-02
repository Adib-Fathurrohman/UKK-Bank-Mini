<?php

use App\Models\Customer;

/*
| NIS/account number + PIN no longer starts a customer session (sessions come
| from a Firebase login, see CustomerFirebaseLoginTest). After a correct PIN
| it tells the app whether the account still has to be activated.
*/

beforeEach(function () {
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => '1000000001',
        'email' => 'budi.santoso@gmail.com',
        'pin' => '123456',
        'balance' => 50000,
    ]);
});

test('a pending customer with a correct NIS and PIN is told to activate, without a token', function () {
    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_required')
        ->assertJsonPath('email', 'b***@gmail.com')
        ->assertJsonMissingPath('data.token');

    expect($this->customer->tokens()->count())->toBe(0);
});

test('the account number works as the identifier too', function () {
    $this->postJson('/api/customer/login', ['identifier' => '1000000001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_required');
});

test('a pending customer without an email is told to contact the administrator', function () {
    $this->customer->update(['email' => null]);

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_required')
        ->assertJsonPath('email', null)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Hubungi administrator'));
});

test('an activated customer must use the Firebase login; the PIN alone gives no token', function () {
    $this->customer->update(['firebase_uid' => 'uid-budi', 'activated_at' => now(), 'email_verified_at' => now()]);

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'firebase_login_required')
        ->assertJsonMissingPath('data.token');

    expect($this->customer->tokens()->count())->toBe(0);
});

test('login fails with a wrong PIN and reveals nothing about activation', function () {
    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.'])
        ->assertJsonMissingPath('code');
});

test('PIN must be exactly six digits', function (string $pin) {
    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => $pin])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
})->with(['12345', '1234567', 'abcdef']);

test('inactive customers cannot log in', function () {
    $this->customer->update(['account_status' => 'inactive']);

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identifier');
});

test('soft deleted customers cannot log in', function () {
    $this->customer->delete();

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertUnprocessable();
});

test('suspended customers get the same activation answer', function () {
    $this->customer->update(['account_status' => 'suspended']);

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_required');
});

test('me returns only the customer that owns the token', function () {
    $other = Customer::factory()->create(['balance' => 999999]);
    $token = $this->customer->createToken('customer-portal')->plainTextToken;

    $this->withToken($token)->getJson('/api/customer/me?customer_id='.$other->id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.balance', 50000)
        ->assertJsonPath('data.email', 'budi.santoso@gmail.com')
        ->assertJsonPath('data.activation_status', 'pending')
        ->assertJsonMissingPath('data.pin')
        ->assertJsonMissingPath('data.firebase_uid');
});

test('logout revokes the current token', function () {
    $token = $this->customer->createToken('customer-portal')->plainTextToken;

    $this->withToken($token)->postJson('/api/customer/logout')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/customer/me')->assertUnauthorized();
});
