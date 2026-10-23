<?php

use App\Models\User;

test('staff can log in with username and password', function () {
    $user = User::factory()->teller()->create(['username' => 'teller1', 'password' => 'rahasia123']);

    $response = $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'rahasia123']);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', 'teller')
        ->assertJsonMissingPath('data.user.password');

    expect($response->json('data.token'))->toBeString()
        ->and($user->tokens()->count())->toBe(1);
});

test('login fails with an unknown username', function () {
    $this->postJson('/api/staff/login', ['username' => 'nobody', 'password' => 'whatever'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('login fails with a wrong password', function () {
    User::factory()->create(['username' => 'teller1', 'password' => 'rahasia123']);

    $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'salah'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('deactivated staff cannot log in', function () {
    User::factory()->inactive()->create(['username' => 'teller1', 'password' => 'rahasia123']);

    $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'rahasia123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('login requires username and password', function () {
    $this->postJson('/api/staff/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'password']);
});

test('the token identifies the logged in staff member', function () {
    $user = User::factory()->supervisor()->create(['username' => 'spv', 'password' => 'rahasia123']);
    $token = $this->postJson('/api/staff/login', ['username' => 'spv', 'password' => 'rahasia123'])->json('data.token');

    $this->withToken($token)->getJson('/api/staff/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'supervisor')
        ->assertJsonMissingPath('data.password');
});

test('protected staff routes reject requests without a token', function () {
    $this->getJson('/api/staff/me')->assertUnauthorized();
    $this->get('/api/staff/me')->assertUnauthorized();
});

test('logout revokes the current token', function () {
    User::factory()->create(['username' => 'teller1', 'password' => 'rahasia123']);
    $token = $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'rahasia123'])->json('data.token');

    $this->withToken($token)->postJson('/api/staff/logout')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/staff/me')->assertUnauthorized();
});

test('login attempts are rate limited', function () {
    User::factory()->create(['username' => 'teller1', 'password' => 'rahasia123']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'salah'])->assertUnprocessable();
    }

    $this->postJson('/api/staff/login', ['username' => 'teller1', 'password' => 'rahasia123'])->assertTooManyRequests();
});
