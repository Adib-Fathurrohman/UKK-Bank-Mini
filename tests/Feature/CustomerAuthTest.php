<?php

use App\Models\Customer;

beforeEach(function () {
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => '1000000001',
        'pin' => '123456',
        'balance' => 50000,
    ]);
});

test('customer can log in with their NIS', function () {
    $response = $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456']);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.customer.balance', 50000)
        ->assertJsonMissingPath('data.customer.pin');
});

test('customer can log in with their account number', function () {
    $this->postJson('/api/customer/login', ['identifier' => '1000000001', 'pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id);
});

test('login fails with a wrong PIN', function () {
    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identifier');
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

test('suspended customers can still log in to view their account', function () {
    $this->customer->update(['account_status' => 'suspended']);

    $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])->assertOk();
});

test('me returns only the customer that owns the token', function () {
    $other = Customer::factory()->create(['balance' => 999999]);
    $token = $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])->json('data.token');

    $this->withToken($token)->getJson('/api/customer/me?customer_id='.$other->id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.balance', 50000)
        ->assertJsonMissingPath('data.pin');
});

test('logout revokes the current token', function () {
    $token = $this->postJson('/api/customer/login', ['identifier' => '20260001', 'pin' => '123456'])->json('data.token');

    $this->withToken($token)->postJson('/api/customer/logout')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/customer/me')->assertUnauthorized();
});
