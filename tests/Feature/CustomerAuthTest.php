<?php

use App\Models\Customer;

/*
| The customer's own session endpoints. Sessions come from a Firebase login or
| activation (CustomerFirebaseLoginTest, CustomerActivationTest); the PIN is
| never a login credential.
*/

beforeEach(function () {
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => '1000000001',
        'email' => 'budi.santoso@gmail.com',
        'balance' => 50000,
    ]);
});

test('NIS or account number + PIN does not log in anywhere', function (array $credentials) {
    $this->postJson('/api/login', $credentials)->assertUnprocessable()->assertJsonMissingPath('data.token');
    $this->postJson('/api/customer/login', $credentials)->assertNotFound();

    expect($this->customer->tokens()->count())->toBe(0);
})->with([
    'NIS' => [['identifier' => '20260001', 'pin' => '123456']],
    'account number' => [['identifier' => '1000000001', 'pin' => '123456']],
]);

test('me returns only the customer that owns the token', function () {
    $other = Customer::factory()->create(['balance' => 999999]);
    $token = $this->customer->createToken('customer-portal')->plainTextToken;

    $this->withToken($token)->getJson('/api/customer/me?customer_id='.$other->id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.balance', 50000)
        ->assertJsonPath('data.email', 'budi.santoso@gmail.com')
        ->assertJsonPath('data.activation_status', 'activated')
        ->assertJsonPath('data.pin_set', true)
        ->assertJsonMissingPath('data.pin')
        ->assertJsonMissingPath('data.firebase_uid');
});

test('logout revokes the current token', function () {
    $token = $this->customer->createToken('customer-portal')->plainTextToken;

    $this->withToken($token)->postJson('/api/customer/logout')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/customer/me')->assertUnauthorized();
});
