<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| First-time activation: the customer signs in to Firebase (Google, or
| email/password verified by Firebase's own email), enters their NIS, and the
| backend binds the Firebase account to the customer whose registered email
| is that verified email. The backend sends no email.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
    Mail::fake();
    $this->firebase = fakeFirebase();
    $this->customer = Customer::factory()->pending()->create([
        'nis' => '20260001',
        'account_number' => 'RPL-2026-001',
        'email' => 'budi.santoso@gmail.com',
    ]);
});

function activate(string $idToken, string $nis = '20260001')
{
    return test()->postJson('/api/customer/activation', ['nis' => $nis, 'id_token' => $idToken]);
}

/*
|--------------------------------------------------------------------------
| New accounts
|--------------------------------------------------------------------------
*/

test('a new account is pending, has no PIN, and no email is sent', function () {
    actingAsStaff(UserRoles::ADMIN);

    $response = $this->postJson('/api/admin/customers', [
        'nis' => '20269999',
        'full_name' => 'Siswa Baru',
        'email' => 'Siswa.Baru@Gmail.com',
        'class' => 'X',
        'major_id' => $this->customer->major_id,
        'group' => '1',
    ])->assertCreated()
        ->assertJsonPath('data.email', 'siswa.baru@gmail.com')
        ->assertJsonPath('data.activation_status', 'pending')
        ->assertJsonPath('data.pin_set', false)
        ->assertJsonMissingPath('invitation');

    expect(Customer::find($response->json('data.id'))->pin)->toBeNull();
    Mail::assertNothingOutgoing();
});

test('the administrator cannot set a PIN', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson('/api/admin/customers', [
        'nis' => '20269999', 'full_name' => 'Siswa Baru', 'email' => 'baru@example.test',
        'class' => 'X', 'major_id' => $this->customer->major_id, 'group' => '1', 'pin' => '123456',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pin' => 'PIN dibuat oleh nasabah di aplikasi Bank Mini setelah aktivasi.']);

    $this->patchJson("/api/admin/customers/{$this->customer->id}", ['pin' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
});

test('a new account can receive a deposit before it is activated', function () {
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);

    $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 50000])->assertCreated();
});

test('the email can be corrected until activation, and not after', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->patchJson("/api/admin/customers/{$this->customer->id}", ['email' => 'budi.baru@gmail.com'])->assertOk();

    $customer = Customer::factory()->create(['email' => 'lama@example.test']);

    $this->patchJson("/api/admin/customers/{$customer->id}", ['email' => 'baru@example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Email tidak dapat diubah setelah rekening diaktifkan.']);

    // Sending the same address with other changes is fine.
    $this->patchJson("/api/admin/customers/{$customer->id}", ['email' => 'lama@example.test', 'class' => 'XI'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

test('Google activation binds the Firebase account and starts a session', function () {
    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))
        ->assertOk()
        ->assertJsonPath('data.actor', 'customer')
        ->assertJsonPath('data.customer.activation_status', 'activated')
        ->assertJsonPath('data.customer.pin_set', false)
        ->assertJsonPath('data.expires_at', fn ($value) => $value !== null);

    $customer = $this->customer->fresh();
    expect($customer->firebase_uid)->toBe('uid-budi')
        ->and($customer->activated_at)->not->toBeNull()
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and($customer->tokens()->count())->toBe(1);

    Mail::assertNothingOutgoing();
});

test('the session from activation works and the Firebase login works afterwards', function () {
    $token = activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->json('data.token');

    $this->withToken($token)->getJson('/api/customer/me')->assertOk()->assertJsonPath('data.id', $this->customer->id);

    $this->postJson('/api/customer/auth/login', ['id_token' => $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com')])->assertOk();
});

test('email/password activation needs the email verified by Firebase first', function () {
    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', emailVerified: false, provider: 'password'))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');

    expect($this->customer->fresh()->isActivated())->toBeFalse();

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', provider: 'password'))->assertOk();
});

test('the verified email must be the registered one, ignoring case', function () {
    activate($this->firebase->issue('uid-other', 'orang.lain@gmail.com'))
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'NIS tidak cocok dengan email akun ini. Masuk dengan email yang didaftarkan di bank mini, atau hubungi petugas.',
            'code' => 'activation_mismatch',
        ]);

    activate($this->firebase->issue('uid-budi', 'Budi.Santoso@Gmail.com'))->assertOk();
});

test('an unknown NIS gets the same answer as another email', function () {
    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'), '99999999')
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_mismatch');

    expect($this->customer->fresh()->isActivated())->toBeFalse();
});

test('an account number is not accepted in place of the NIS', function () {
    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'), 'RPL-2026-001')
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_mismatch');
});

test('an account without a registered email cannot be activated', function () {
    $this->customer->update(['email' => null]);

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertJsonPath('code', 'activation_mismatch');
});

test('a Firebase account bound to another customer cannot claim a second account', function () {
    Customer::factory()->activated('uid-budi')->create(['email' => 'budi.lain@gmail.com']);

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))
        ->assertConflict()
        ->assertJsonPath('code', 'identity_already_linked');
});

test('an activated account cannot be claimed by another Firebase account, but the same one just signs in again', function () {
    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertOk();

    // A second Firebase user with the same verified email (e.g. a deleted and recreated account).
    activate($this->firebase->issue('uid-budi-2', 'budi.santoso@gmail.com'))
        ->assertConflict()
        ->assertJsonPath('code', 'already_activated');

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertOk();
    expect($this->customer->fresh()->firebase_uid)->toBe('uid-budi');
});

test('a closed account cannot be activated', function () {
    $this->customer->update(['account_status' => 'inactive']);
    $this->customer->delete();

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');
});

test('a suspended account can be activated (to see its balance)', function () {
    $this->customer->update(['account_status' => 'suspended']);

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertOk();
});

test('invalid tokens and unavailable Firebase are reported with their codes', function () {
    activate('not-a-real-token')->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    activate($this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', provider: 'phone'))
        ->assertForbidden()
        ->assertJsonPath('code', 'provider_not_allowed');

    $this->firebase->unavailable = true;
    activate('any-token')->assertStatus(503)->assertJsonPath('code', 'auth_unavailable');

    expect($this->customer->fresh()->isActivated())->toBeFalse();
});

test('activation needs the NIS and the ID token', function () {
    $this->postJson('/api/customer/activation', [])->assertUnprocessable()->assertJsonValidationErrors(['nis', 'id_token']);
});

test('activation is rate limited per IP', function () {
    foreach (range(1, 20) as $attempt) {
        activate('not-a-real-token')->assertUnauthorized();
    }

    activate('not-a-real-token')->assertTooManyRequests();
});

test('the old link-based activation endpoints are gone', function (string $method, string $uri) {
    $this->json($method, $uri)->assertNotFound();
})->with([
    ['POST', '/api/customer/activation/inspect'],
    ['POST', '/api/customer/activation/complete'],
    ['POST', '/api/customer/activation/resend'],
    ['POST', '/api/customer/login'],
]);

/*
|--------------------------------------------------------------------------
| Database guarantees
|--------------------------------------------------------------------------
*/

test('the database refuses a Firebase UID or email on two accounts', function (string $column, string $value) {
    Customer::factory()->activated('uid-same')->create(['email' => 'same@example.test']);

    expect(fn () => Customer::factory()->create([$column => $value]))->toThrow(QueryException::class);
})->with([
    'firebase_uid' => ['firebase_uid', 'uid-same'],
    'email' => ['email', 'same@example.test'],
]);

test('the database refuses an activated account without a Firebase UID', function () {
    expect(fn () => DB::table('customers')->where('id', $this->customer->id)->update(['activated_at' => now()]))
        ->toThrow(QueryException::class);
});

test('the database refuses a PIN on an account that is not activated', function () {
    expect(fn () => DB::table('customers')->where('id', $this->customer->id)->update(['pin' => bcrypt('123456')]))
        ->toThrow(QueryException::class);
});

test('existing accounts without an email keep working at the counter', function () {
    $legacy = Customer::factory()->pending()->create(['email' => null, 'nis' => '20250001', 'account_number' => 'RPL-2025-001']);
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);

    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2025-001')->assertOk();
    $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2025-001', 'amount' => 20000])->assertCreated();
    expect($legacy->fresh()->balance)->toBe(20000);
});
