<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
| Customer sessions: long-lived tokens (CUSTOMER_SESSION_MINUTES), the PIN as
| an app unlock while the session lasts, logout, and what deactivation does.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
    $this->firebase = fakeFirebase();
    $this->customer = Customer::factory()->activated('uid-budi')->create(['email' => 'budi@example.test', 'pin' => '123456']);
});

function customerSessionToken(Customer $customer): string
{
    return app(CustomerAuthService::class)->startSession($customer)['token'];
}

function getAsToken(string $token, string $uri)
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson($uri);
}

test('a customer session outlives the 8-hour staff expiry and ends after its own lifetime', function () {
    $token = customerSessionToken($this->customer);

    $this->travel(9)->hours();
    getAsToken($token, '/api/customer/me')->assertOk();

    $this->travelTo(Carbon::parse('2026-12-02 08:59:00'));
    getAsToken($token, '/api/customer/me')->assertOk();

    $this->travelTo(Carbon::parse('2026-12-02 09:00:01'));
    getAsToken($token, '/api/customer/me')->assertUnauthorized();
});

test('staff tokens and older customer tokens keep the 8-hour expiry', function () {
    $staffToken = User::factory()->teller()->create()->createToken('staff-portal')->plainTextToken;
    $legacyCustomerToken = $this->customer->createToken('customer-portal')->plainTextToken;

    $this->travel(479)->minutes();
    getAsToken($staffToken, '/api/staff/me')->assertOk();
    getAsToken($legacyCustomerToken, '/api/customer/me')->assertOk();

    $this->travel(2)->minutes();
    getAsToken($staffToken, '/api/staff/me')->assertUnauthorized();
    getAsToken($legacyCustomerToken, '/api/customer/me')->assertUnauthorized();
});

test('the PIN unlocks the app during a session', function () {
    $token = customerSessionToken($this->customer);

    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('message', 'PIN benar.');
});

test('wrong PINs at unlock count toward the shared lock', function () {
    $token = customerSessionToken($this->customer);

    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('remaining_attempts', 2);
    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '000000']);
    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('pin_locked_until', '2026-11-02T02:15:00.000000Z');

    // Locked everywhere: even the correct PIN, and the PIN change.
    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '123456'])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/customer/pin', ['current_pin' => '123456', 'new_pin' => '246810', 'new_pin_confirmation' => '246810'])
        ->assertUnprocessable();
});

test('the PIN cannot be used without a session', function () {
    $this->postJson('/api/customer/unlock', ['pin' => '123456'])->assertUnauthorized();

    $token = customerSessionToken($this->customer);
    $this->travelTo(Carbon::parse('2026-12-03 09:00:00'));
    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '123456'])->assertUnauthorized();
});

test('staff cannot use the unlock endpoint', function (UserRoles $role) {
    actingAsStaff($role);

    $this->postJson('/api/customer/unlock', ['pin' => '123456'])->assertForbidden();
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR, UserRoles::TELLER]);

test('unlocking is rate limited per customer', function () {
    $token = customerSessionToken($this->customer);

    foreach (range(1, 5) as $attempt) {
        $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '123456'])->assertOk();
    }

    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '123456'])->assertTooManyRequests();
});

test('logout ends only the current session; logout-all ends every session', function () {
    $phone = customerSessionToken($this->customer);
    $tablet = customerSessionToken($this->customer);
    $laptop = customerSessionToken($this->customer);

    $this->withToken($phone)->postJson('/api/customer/logout')->assertOk();
    getAsToken($phone, '/api/customer/me')->assertUnauthorized();
    getAsToken($tablet, '/api/customer/me')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($tablet)->postJson('/api/customer/logout-all')
        ->assertOk()
        ->assertJsonPath('message', 'Berhasil keluar dari semua perangkat.');

    getAsToken($tablet, '/api/customer/me')->assertUnauthorized();
    getAsToken($laptop, '/api/customer/me')->assertUnauthorized();
    expect($this->customer->tokens()->count())->toBe(0);
});

test('deactivation ends all sessions, disables the Firebase user and blocks new logins', function () {
    $token = customerSessionToken($this->customer);
    $idToken = $this->firebase->issue('uid-budi', 'budi@example.test');
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$this->customer->id}/deactivate")->assertOk();

    expect($this->customer->tokens()->count())->toBe(0)
        ->and($this->firebase->disabledUids)->toBe(['uid-budi']);

    getAsToken($token, '/api/customer/me')->assertUnauthorized();

    // Even if Firebase still accepted the account, the backend refuses it.
    $this->firebase->disabledUids = [];
    $this->postJson('/api/customer/auth/login', ['id_token' => $idToken])
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');

    // Banking history is kept.
    expect(Customer::withTrashed()->find($this->customer->id))->not->toBeNull();
});

test('deactivation still succeeds when Firebase is unreachable', function () {
    Log::spy();
    $this->firebase->unavailable = true;
    $token = customerSessionToken($this->customer);
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$this->customer->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.account_status', 'inactive');

    expect($this->customer->tokens()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Firebase user'))->once();
    getAsToken($token, '/api/customer/me')->assertUnauthorized();
});

test('deactivating a pending customer does not call Firebase', function () {
    $pending = Customer::factory()->create();
    $this->firebase->unavailable = true;
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$pending->id}/deactivate")->assertOk();

    expect($this->firebase->disabledUids)->toBe([]);
});

test('withdrawal approval in the app keeps requiring the PIN within a session', function () {
    allowTerminalWithdrawals('mobile');
    $this->customer->update(['balance' => 100000, 'account_number' => 'RPL-2026-001']);
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);
    $id = $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 20000])
        ->assertCreated()->json('data.id');

    $token = customerSessionToken($this->customer);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", [])->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->withToken($token)->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => '000000'])->assertUnprocessable();
    $this->withToken($token)->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => '123456'])->assertOk();
});
