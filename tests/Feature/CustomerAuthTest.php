<?php

use App\Models\Customer;

/*
| Customers sign in with their NIS or account number + PIN. The session lasts
| CUSTOMER_SESSION_MINUTES; the response says whether the PIN must be changed.
*/

beforeEach(function () {
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => '1000000001',
        'email' => null,
        'pin' => '123456',
        'balance' => 50000,
    ]);
});

function customerLoginAs(string $identifier = '20260001', string $pin = '123456')
{
    return test()->postJson('/api/customer/login', ['identifier' => $identifier, 'pin' => $pin]);
}

test('a customer without an email logs in with their NIS', function () {
    customerLoginAs()
        ->assertOk()
        ->assertJsonPath('data.actor', 'customer')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.must_change_pin', false)
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.customer.email', null)
        ->assertJsonPath('data.customer.pin_must_change', false)
        ->assertJsonMissingPath('data.customer.pin');
});

test('the account number works as the identifier too', function () {
    customerLoginAs('1000000001')->assertOk()->assertJsonPath('data.customer.id', $this->customer->id);
});

test('the login gateway accepts NIS + PIN', function () {
    $this->postJson('/api/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.actor', 'customer');
});

test('login fails with a wrong PIN', function () {
    customerLoginAs(pin: '000000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.']);
});

test('PIN must be exactly six digits', function (string $pin) {
    customerLoginAs(pin: $pin)->assertUnprocessable()->assertJsonValidationErrors('pin');
})->with(['12345', '1234567', 'abcdef']);

test('inactive and closed accounts cannot log in', function () {
    $this->customer->update(['account_status' => 'inactive']);
    customerLoginAs()->assertUnprocessable()->assertJsonValidationErrors(['identifier' => 'Rekening ini sudah tidak aktif.']);

    $this->customer->delete();
    customerLoginAs()->assertUnprocessable()->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.']);
});

test('suspended customers can still log in to view their account', function () {
    $this->customer->update(['account_status' => 'suspended']);

    customerLoginAs()->assertOk();
});

test('me returns only the customer that owns the token', function () {
    $other = Customer::factory()->create(['balance' => 999999]);
    $token = customerLoginAs()->json('data.token');

    $this->withToken($token)->getJson('/api/customer/me?customer_id='.$other->id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.balance', 50000)
        ->assertJsonMissingPath('data.pin');
});

test('logout revokes the current token', function () {
    $token = customerLoginAs()->json('data.token');

    $this->withToken($token)->postJson('/api/customer/logout')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/customer/me')->assertUnauthorized();
});

test('the Firebase login endpoints are gone', function (string $uri) {
    $this->postJson($uri, ['id_token' => 'x'])->assertNotFound();
})->with(['/api/customer/auth/login', '/api/customer/activation', '/api/customer/pin/setup']);
