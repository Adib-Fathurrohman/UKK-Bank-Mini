<?php

use App\Models\Customer;
use App\Models\User;

test('staff log in through the single gateway', function () {
    $user = User::factory()->supervisor()->create(['username' => 'spv', 'password' => 'rahasia123']);

    $this->postJson('/api/login', ['username' => 'spv', 'password' => 'rahasia123'])
        ->assertOk()
        ->assertJsonPath('data.actor', 'staff')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', 'supervisor');
});

test('customers log in through the single gateway', function () {
    $customer = Customer::factory()->create(['nis' => '20260001', 'pin' => '123456']);

    $this->postJson('/api/login', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.actor', 'customer')
        ->assertJsonPath('data.customer.id', $customer->id);
});

test('the gateway validates each kind of credentials', function () {
    $this->postJson('/api/login', ['username' => 'spv'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->postJson('/api/login', ['identifier' => '20260001', 'pin' => '12'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');

    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('wrong credentials are rejected through the gateway', function () {
    User::factory()->create(['username' => 'spv', 'password' => 'rahasia123']);

    $this->postJson('/api/login', ['username' => 'spv', 'password' => 'salah'])->assertUnprocessable();
});

test('gateway tokens only work on their own portal', function () {
    User::factory()->teller()->create(['username' => 'teller1', 'password' => 'rahasia123']);
    $token = $this->postJson('/api/login', ['username' => 'teller1', 'password' => 'rahasia123'])->json('data.token');

    $this->withToken($token)->getJson('/api/staff/me')->assertOk();
    $this->withToken($token)->getJson('/api/customer/me')->assertForbidden();
});

test('the gateway is rate limited', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', ['username' => 'nobody', 'password' => 'salah'])->assertUnprocessable();
    }

    $this->postJson('/api/login', ['username' => 'nobody', 'password' => 'salah'])->assertTooManyRequests();
});
