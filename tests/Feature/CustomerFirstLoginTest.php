<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Major;
use App\Rules\NotWeakPin;
use Illuminate\Support\Facades\Hash;

/*
| New accounts get a temporary PIN from the system, shown once to the
| administrator. At the first login the customer must replace it: until then
| the session can only read the profile, change the PIN and log out. An
| administrator reset hands out a new temporary PIN the same way.
*/

function loginWith(string $nis, string $pin)
{
    return test()->postJson('/api/customer/login', ['identifier' => $nis, 'pin' => $pin]);
}

function changePin(string $token, string $current, string $new, ?string $confirmation = null)
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->postJson('/api/customer/pin', [
        'current_pin' => $current,
        'new_pin' => $new,
        'new_pin_confirmation' => $confirmation ?? $new,
    ]);
}

test('a new account gets a random temporary PIN, shown once, that must be changed', function () {
    actingAsStaff(UserRoles::ADMIN);

    $response = $this->postJson('/api/admin/customers', [
        'nis' => '20269999',
        'full_name' => 'Siswa Baru',
        'class' => 'X',
        'major_id' => Major::factory()->create()->id,
        'group' => '1',
    ])->assertCreated()
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.pin_must_change', true)
        ->assertJsonPath('temporary_pin', fn ($pin) => preg_match('/^\d{6}$/', $pin) === 1)
        ->assertJsonMissingPath('data.pin');

    $customer = Customer::firstWhere('nis', '20269999');
    $temporaryPin = $response->json('temporary_pin');

    expect(Hash::check($temporaryPin, $customer->pin))->toBeTrue()
        ->and(NotWeakPin::isWeak($temporaryPin))->toBeFalse();

    // The PIN is not shown again.
    $this->getJson("/api/customers/{$customer->id}")->assertJsonMissingPath('temporary_pin');
});

test('the administrator cannot choose a PIN', function () {
    actingAsStaff(UserRoles::ADMIN);
    $customer = Customer::factory()->create();

    $this->postJson('/api/admin/customers', ['nis' => '1', 'full_name' => 'X', 'class' => 'X', 'major_id' => $customer->major_id, 'group' => '1', 'pin' => '246810'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pin' => 'PIN sementara dibuat otomatis oleh sistem.']);

    $this->patchJson("/api/admin/customers/{$customer->id}", ['pin' => '246810'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
});

test('the first login with the temporary PIN only allows the PIN change', function () {
    Customer::factory()->mustChangePin()->create(['nis' => '20260001', 'pin' => '483920']);

    $token = loginWith('20260001', '483920')
        ->assertOk()
        ->assertJsonPath('data.must_change_pin', true)
        ->json('data.token');

    foreach ([['GET', '/api/customer/balance'], ['GET', '/api/customer/transactions'], ['POST', '/api/customer/unlock'], ['GET', '/api/customer/withdrawal-authorizations'], ['POST', '/api/customer/push-tokens']] as [$method, $uri]) {
        app('auth')->forgetGuards();
        $this->withToken($token)->json($method, $uri, ['pin' => '483920', 'token' => 'x', 'platform' => 'android'])
            ->assertStatus(428)
            ->assertExactJson(['message' => 'Ganti PIN sementara Anda terlebih dahulu.', 'code' => 'pin_change_required']);
    }

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/customer/me')->assertOk()->assertJsonPath('data.pin_must_change', true);
});

test('after the PIN change the same session has full access and the new PIN works', function () {
    $customer = Customer::factory()->mustChangePin()->create(['nis' => '20260001', 'pin' => '483920']);
    $token = loginWith('20260001', '483920')->json('data.token');

    changePin($token, '483920', '715302')->assertOk()->assertJsonPath('message', 'PIN berhasil diubah.');

    expect($customer->fresh()->pin_must_change)->toBeFalse();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/customer/balance')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/customer/unlock', ['pin' => '715302'])->assertOk();

    loginWith('20260001', '483920')->assertUnprocessable();
    loginWith('20260001', '715302')->assertOk()->assertJsonPath('data.must_change_pin', false);
});

test('the new PIN must differ from the temporary one and not be easy to guess', function (string $new, ?string $confirmation, string $error) {
    Customer::factory()->mustChangePin()->create(['nis' => '20260001', 'pin' => '483920']);
    $token = loginWith('20260001', '483920')->json('data.token');

    changePin($token, '483920', $new, $confirmation)->assertUnprocessable()->assertJsonValidationErrors(['new_pin' => $error]);
})->with([
    'same as temporary' => ['483920', null, 'different'],
    'repeated digit' => ['111111', null, 'mudah ditebak'],
    'ascending run' => ['123456', null, 'mudah ditebak'],
    'descending run' => ['987654', null, 'mudah ditebak'],
    'not confirmed' => ['715302', '715303', 'confirmation'],
]);

test('a wrong current PIN during the change counts toward the lock', function () {
    $customer = Customer::factory()->mustChangePin()->create(['nis' => '20260001', 'pin' => '483920']);
    $token = loginWith('20260001', '483920')->json('data.token');

    changePin($token, '000000', '715302')->assertUnprocessable()->assertJsonPath('remaining_attempts', 2);

    expect($customer->fresh()->pin_must_change)->toBeTrue();
});

test('an administrator reset gives a new temporary PIN, ends the sessions and lifts the lock', function () {
    $customer = Customer::factory()->create(['nis' => '20260001', 'pin' => '715302', 'pin_locked_until' => now()->addMinutes(10)]);
    $session = $customer->createToken('customer-portal')->plainTextToken;
    actingAsStaff(UserRoles::ADMIN);

    $response = $this->postJson("/api/admin/customers/{$customer->id}/pin-reset")
        ->assertOk()
        ->assertJsonPath('message', 'PIN direset. Berikan PIN sementara kepada nasabah; nasabah wajib menggantinya saat masuk.')
        ->assertJsonPath('data.pin_must_change', true)
        ->assertJsonPath('temporary_pin', fn ($pin) => preg_match('/^\d{6}$/', $pin) === 1);

    $customer->refresh();
    expect($customer->tokens()->count())->toBe(0)
        ->and($customer->pin_locked_until)->toBeNull()
        ->and($customer->pin_must_change)->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($session)->getJson('/api/customer/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    loginWith('20260001', $response->json('temporary_pin'))->assertOk()->assertJsonPath('data.must_change_pin', true);
});

test('admins can list who still has the temporary PIN', function () {
    actingAsStaff(UserRoles::ADMIN);
    $temporary = Customer::factory()->mustChangePin()->create();
    Customer::factory()->create();

    $this->getJson('/api/customers?pin_must_change=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $temporary->id);
});
