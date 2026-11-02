<?php

use App\Models\Customer;
use Illuminate\Support\Carbon;

/*
| POST /api/customer/auth/login: a verified Firebase ID token starts a
| customer session. The customer is found by the token's UID only.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
    $this->firebase = fakeFirebase();
    $this->customer = Customer::factory()->activated('uid-budi')->create([
        'email' => 'budi@example.test',
        'balance' => 50000,
    ]);
});

function firebaseLogin(string $idToken, array $extra = [])
{
    return test()->postJson('/api/customer/auth/login', ['id_token' => $idToken, ...$extra]);
}

test('a verified Firebase token starts a session in the usual response shape', function () {
    $response = firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test'));

    $response->assertOk()
        ->assertJsonPath('data.actor', 'customer')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_at', '2026-12-02T02:00:00.000000Z')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.customer.balance', 50000)
        ->assertJsonMissingPath('data.customer.pin')
        ->assertJsonMissingPath('data.customer.firebase_uid');

    expect($this->customer->tokens()->sole()->expires_at->toDateTimeString())->toBe('2026-12-02 09:00:00');

    $this->withToken($response->json('data.token'))->getJson('/api/customer/me')->assertOk();
});

test('the session length is configurable', function () {
    config(['bank.customer_session_minutes' => 60 * 24 * 14]);

    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test'))
        ->assertJsonPath('data.expires_at', '2026-11-16T02:00:00.000000Z');
});

test('the customer comes from the verified UID, never from the request body', function () {
    $other = Customer::factory()->activated('uid-other')->create(['balance' => 999999]);

    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test'), ['customer_id' => $other->id, 'uid' => 'uid-other'])
        ->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id);
});

test('a matching email does not log in an unlinked Firebase account', function () {
    // Same email as Budi, different Firebase user: never linked automatically.
    firebaseLogin($this->firebase->issue('uid-stranger', 'budi@example.test'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_linked')
        ->assertJsonMissingPath('data.token');

    expect(Customer::where('firebase_uid', 'uid-stranger')->exists())->toBeFalse();
});

test('a pending customer cannot log in with Firebase before activating', function () {
    Customer::factory()->create(['email' => 'pending@example.test']);

    firebaseLogin($this->firebase->issue('uid-pending', 'pending@example.test'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_linked');
});

test('invalid, revoked and disabled tokens are refused alike', function () {
    firebaseLogin('not-a-real-token')->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    $revoked = $this->firebase->issue('uid-budi', 'budi@example.test');
    $this->firebase->revoke($revoked);
    firebaseLogin($revoked)->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    $token = $this->firebase->issue('uid-budi', 'budi@example.test');
    $this->firebase->disabledUids[] = 'uid-budi';
    firebaseLogin($token)->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    expect($this->customer->tokens()->count())->toBe(0);
});

test('sign-in providers other than password and Google are refused', function () {
    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test', provider: 'anonymous'))
        ->assertForbidden()
        ->assertJsonPath('code', 'provider_not_allowed');
});

test('a token whose email is no longer verified is refused', function () {
    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test', emailVerified: false, provider: 'password'))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');
});

test('closed accounts get no new session even with a valid Firebase session', function () {
    $this->customer->update(['account_status' => 'inactive']);
    $this->customer->delete();

    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');

    expect($this->customer->tokens()->count())->toBe(0);
});

test('suspended customers can still log in to view their account', function () {
    $this->customer->update(['account_status' => 'suspended']);

    firebaseLogin($this->firebase->issue('uid-budi', 'budi@example.test'))->assertOk();
});

test('a Firebase outage returns 503', function () {
    $token = $this->firebase->issue('uid-budi', 'budi@example.test');
    $this->firebase->unavailable = true;

    firebaseLogin($token)->assertStatus(503)->assertJsonPath('code', 'auth_unavailable');
});

test('the id token is required', function () {
    $this->postJson('/api/customer/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors('id_token');
});

test('Firebase logins are limited per IP, generously', function () {
    foreach (range(1, 30) as $attempt) {
        firebaseLogin('bad-token-'.$attempt)->assertUnauthorized();
    }

    firebaseLogin('bad-token')->assertTooManyRequests();
});
