<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\CustomerPushToken;

/*
| The customer app registers the phone's FCM token. Each token belongs to the
| session that registered it: logout, logout-all, a PIN reset and
| deactivation remove it.
*/

beforeEach(function () {
    $this->customer = Customer::factory()->create(['nis' => '20260001']);
    $this->session = $this->customer->createToken('customer-portal')->plainTextToken;
});

function registerPushToken(string $session, string $token = 'fcm-token-phone', string $platform = 'android')
{
    app('auth')->forgetGuards();

    return test()->withToken($session)->postJson('/api/customer/push-tokens', ['token' => $token, 'platform' => $platform]);
}

test('the app registers the phone token for this session', function () {
    registerPushToken($this->session)->assertCreated()->assertJsonPath('message', 'Notifikasi aktif di perangkat ini.');

    $pushToken = CustomerPushToken::sole();
    expect($pushToken->customer_id)->toBe($this->customer->id)
        ->and($pushToken->platform)->toBe('android')
        ->and($pushToken->personal_access_token_id)->toBe($this->customer->tokens()->sole()->id);

    // Registering again (every app start) is fine.
    registerPushToken($this->session)->assertOk();
    expect(CustomerPushToken::count())->toBe(1);
});

test('a token is validated', function (array $body, string $field) {
    $this->withToken($this->session)->postJson('/api/customer/push-tokens', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing token' => [['platform' => 'android'], 'token'],
    'unknown platform' => [['token' => 'x', 'platform' => 'web'], 'platform'],
    'too long' => [['token' => str_repeat('x', 256), 'platform' => 'ios'], 'token'],
]);

test('a shared phone pushes only to the customer who logged in last', function () {
    $other = Customer::factory()->create();
    registerPushToken($other->createToken('customer-portal')->plainTextToken, 'shared-phone');

    registerPushToken($this->session, 'shared-phone')->assertOk();

    expect(CustomerPushToken::sole()->customer_id)->toBe($this->customer->id);
});

test('the customer can turn pushes off for this phone', function () {
    registerPushToken($this->session);

    app('auth')->forgetGuards();
    $this->withToken($this->session)->deleteJson('/api/customer/push-tokens', ['token' => 'fcm-token-phone'])
        ->assertOk()
        ->assertJsonPath('message', 'Notifikasi dimatikan di perangkat ini.');

    expect(CustomerPushToken::count())->toBe(0);
});

test('a customer cannot remove another customer\'s token', function () {
    $other = Customer::factory()->create();
    registerPushToken($other->createToken('customer-portal')->plainTextToken, 'other-phone');

    app('auth')->forgetGuards();
    $this->withToken($this->session)->deleteJson('/api/customer/push-tokens', ['token' => 'other-phone'])->assertOk();

    expect(CustomerPushToken::where('token', 'other-phone')->exists())->toBeTrue();
});

test('the token goes away with its session', function (Closure $endSession) {
    registerPushToken($this->session);

    $endSession($this);

    expect(CustomerPushToken::count())->toBe(0);
})->with([
    'logout' => [fn ($test) => $test->withToken($test->session)->postJson('/api/customer/logout')->assertOk()],
    'logout-all' => [fn ($test) => $test->withToken($test->session)->postJson('/api/customer/logout-all')->assertOk()],
    'PIN reset' => [function ($test) {
        app('auth')->forgetGuards();
        actingAsStaff(UserRoles::ADMIN);
        $test->postJson("/api/admin/customers/{$test->customer->id}/pin-reset")->assertOk();
    }],
    'deactivation' => [function ($test) {
        app('auth')->forgetGuards();
        actingAsStaff(UserRoles::ADMIN);
        $test->postJson("/api/admin/customers/{$test->customer->id}/deactivate")->assertOk();
    }],
]);
