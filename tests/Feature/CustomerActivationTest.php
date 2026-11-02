<?php

use App\Enums\UserRoles;
use App\Mail\CustomerActivatedMail;
use App\Mail\CustomerInvitationMail;
use App\Models\Customer;
use App\Models\CustomerActivationToken;
use App\Models\Major;
use App\Services\CustomerActivationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| First-time activation: the administrator's invitation email, the activation
| page, and binding a Firebase identity (Google or email/password) to the
| invited account exactly once.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
    Mail::fake();
    $this->firebase = fakeFirebase();
    $this->customer = Customer::factory()->create([
        'nis' => '20260001',
        'account_number' => 'RPL-2026-001',
        'email' => 'budi.santoso@gmail.com',
        'pin' => '123456',
    ]);
});

/**
 * Invite the customer and return the plain token from the email.
 */
function inviteAndGetToken(Customer $customer): string
{
    app(CustomerActivationService::class)->invite($customer);

    parse_str(parse_url(collect(Mail::sent(CustomerInvitationMail::class))->last()->activationUrl, PHP_URL_QUERY), $query);

    return $query['token'];
}

function completeActivation(string $token, string $idToken)
{
    return test()->postJson('/api/customer/activation/complete', ['token' => $token, 'id_token' => $idToken]);
}

/*
|--------------------------------------------------------------------------
| Invitation
|--------------------------------------------------------------------------
*/

test('creating an account emails a single-use activation link and stores only its hash', function () {
    actingAsStaff(UserRoles::ADMIN);

    $response = $this->postJson('/api/admin/customers', [
        'nis' => '20269999',
        'full_name' => 'Siswa Baru',
        'email' => '  Siswa.Baru@Example.TEST ',
        'class' => 'X',
        'major_id' => Major::factory()->create()->id,
        'group' => '1',
        'pin' => '246810',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'siswa.baru@example.test')
        ->assertJsonPath('data.activation_status', 'pending')
        ->assertJsonPath('data.account_status', 'active')
        ->assertJsonPath('invitation.sent_to', 'siswa.baru@example.test')
        ->assertJsonPath('invitation.mail_sent', true)
        ->assertJsonPath('invitation.expires_at', '2026-11-03T02:00:00.000000Z');

    $customer = Customer::firstWhere('nis', '20269999');
    $mail = collect(Mail::sent(CustomerInvitationMail::class))->sole();
    expect($mail->hasTo('siswa.baru@example.test'))->toBeTrue()
        ->and($mail->isResend)->toBeFalse()
        ->and($mail->activationUrl)->toStartWith(config('bank.customer_activation_url').'?token=');

    parse_str(parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
    $record = $customer->activationTokens()->sole();

    expect($query['token'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($record->token_hash)->toBe(hash('sha256', $query['token']))
        ->and(DB::table('customer_activation_tokens')->where('token_hash', $query['token'])->exists())->toBeFalse()
        ->and($response->json())->not->toHaveKey('invitation.token');
});

test('a new account can receive a deposit before it is activated', function () {
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);

    $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 50000])->assertCreated();
});

test('the account is still created when the email cannot be sent', function () {
    actingAsStaff(UserRoles::ADMIN);
    // Unreachable SMTP server instead of the fake.
    Mail::swap(new Illuminate\Mail\MailManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $this->postJson('/api/admin/customers', [
        'nis' => '20269999', 'full_name' => 'Siswa Baru', 'email' => 'baru@example.test', 'class' => 'X',
        'major_id' => Major::factory()->create()->id, 'group' => '1', 'pin' => '246810',
    ])->assertCreated()->assertJsonPath('invitation.mail_sent', false);

    expect(Customer::firstWhere('nis', '20269999')->activationTokens()->outstanding()->count())->toBe(1);
});

test('the invitation email is branded, in Indonesian, and contains the link and expiry', function () {
    $html = (new CustomerInvitationMail($this->customer, 'https://app.example.test/aktivasi?token=abc', now()->addDay()))->render();

    expect($html)->toContain(config('bank.name'))
        ->toContain('RPL-2026-001')
        ->toContain('budi.santoso@gmail.com')
        ->toContain('https://app.example.test/aktivasi?token=abc')
        ->toContain('3 November 2026, 09:00')
        ->not->toContain('123456');
});

test('an administrator can resend the invitation; only the newest link works', function () {
    $first = inviteAndGetToken($this->customer);
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson("/api/admin/customers/{$this->customer->id}/invitation")
        ->assertOk()
        ->assertJsonPath('data.mail_sent', true);

    $mail = collect(Mail::sent(CustomerInvitationMail::class))->last();
    parse_str(parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
    expect($mail->isResend)->toBeTrue();

    $this->postJson('/api/customer/activation/inspect', ['token' => $first])->assertNotFound()->assertJsonPath('code', 'activation_invalid');
    $this->postJson('/api/customer/activation/inspect', ['token' => $query['token']])->assertOk();
});

test('invitations cannot be sent to activated accounts or accounts without an email', function () {
    actingAsStaff(UserRoles::ADMIN);
    $activated = Customer::factory()->activated()->create();
    $withoutEmail = Customer::factory()->create(['email' => null]);

    $this->postJson("/api/admin/customers/{$activated->id}/invitation")->assertStatus(409)->assertJsonPath('code', 'already_activated');
    $this->postJson("/api/admin/customers/{$withoutEmail->id}/invitation")->assertUnprocessable()->assertJsonPath('code', 'email_missing');
    Mail::assertNothingSent();
});

test('only administrators can send invitations', function (UserRoles $role) {
    actingAsStaff($role);

    $this->postJson("/api/admin/customers/{$this->customer->id}/invitation")->assertForbidden();
})->with([UserRoles::SUPERVISOR, UserRoles::TELLER]);

test('resending is rate limited per customer', function () {
    actingAsStaff(UserRoles::ADMIN);

    foreach (range(1, 5) as $attempt) {
        $this->postJson("/api/admin/customers/{$this->customer->id}/invitation")->assertOk();
    }

    $this->postJson("/api/admin/customers/{$this->customer->id}/invitation")->assertTooManyRequests();
});

test('changing the email of a pending account revokes links sent to the old address', function () {
    $token = inviteAndGetToken($this->customer);
    actingAsStaff(UserRoles::ADMIN);

    $this->patchJson("/api/admin/customers/{$this->customer->id}", ['email' => 'budi.baru@gmail.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'budi.baru@gmail.com');

    $this->postJson('/api/customer/activation/inspect', ['token' => $token])->assertNotFound();
});

test('the email cannot be changed after activation', function () {
    actingAsStaff(UserRoles::ADMIN);
    $customer = Customer::factory()->activated()->create(['email' => 'lama@example.test']);

    $this->patchJson("/api/admin/customers/{$customer->id}", ['email' => 'baru@example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Email tidak dapat diubah setelah rekening diaktifkan.']);

    // Sending the same address with other changes is fine.
    $this->patchJson("/api/admin/customers/{$customer->id}", ['email' => 'lama@example.test', 'class' => 'XI'])->assertOk();
});

test('a pending customer can ask for a new link with NIS and PIN', function () {
    inviteAndGetToken($this->customer);

    $this->postJson('/api/customer/activation/resend', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.email', 'b***@gmail.com')
        ->assertJsonPath('data.mail_sent', true)
        ->assertJsonMissingPath('data.sent_to');

    Mail::assertSent(CustomerInvitationMail::class, 2);
    expect($this->customer->activationTokens()->latest('id')->first()->issued_by)->toBeNull()
        ->and($this->customer->activationTokens()->outstanding()->count())->toBe(1);
});

test('asking for a new link needs the correct PIN, which counts toward the lock', function () {
    $this->postJson('/api/customer/activation/resend', ['identifier' => '20260001', 'pin' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier' => 'NIS/nomor rekening atau PIN salah.']);

    expect($this->customer->fresh()->pin_failed_attempts)->toBe(1);
    Mail::assertNothingSent();
});

test('activated or email-less customers do not get a new link', function () {
    $this->customer->update(['firebase_uid' => 'uid-budi', 'activated_at' => now()]);
    $this->postJson('/api/customer/activation/resend', ['identifier' => '20260001', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'firebase_login_required');

    $other = Customer::factory()->create(['nis' => '20260002', 'email' => null, 'pin' => '123456']);
    $this->postJson('/api/customer/activation/resend', ['identifier' => '20260002', 'pin' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('code', 'activation_required')
        ->assertJsonPath('email', null);

    Mail::assertNothingSent();
});

test('customers can ask for at most three new links per hour', function () {
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/customer/activation/resend', ['identifier' => '20260001', 'pin' => '123456'])->assertOk();
    }

    $this->postJson('/api/customer/activation/resend', ['identifier' => '20260001', 'pin' => '123456'])->assertTooManyRequests();
});

/*
|--------------------------------------------------------------------------
| Activation page
|--------------------------------------------------------------------------
*/

test('the activation page shows the registered email and expiry', function () {
    $token = inviteAndGetToken($this->customer);

    $this->postJson('/api/customer/activation/inspect', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.email', 'budi.santoso@gmail.com')
        ->assertJsonPath('data.full_name', $this->customer->full_name)
        ->assertJsonPath('data.account_number', 'RPL-2026-001')
        ->assertJsonPath('data.providers', ['password', 'google.com'])
        ->assertJsonMissingPath('data.balance');
});

test('unknown and malformed links are invalid', function (string $token, int $status) {
    $this->postJson('/api/customer/activation/inspect', ['token' => $token])->assertStatus($status);
})->with([
    'unknown' => [str_repeat('a', 64), 404],
    'too short' => ['abc', 422],
]);

test('an expired link says so, so the app can offer a new one', function () {
    $token = inviteAndGetToken($this->customer);
    $this->travel(24)->hours();
    $this->travel(1)->seconds();

    $this->postJson('/api/customer/activation/inspect', ['token' => $token])
        ->assertStatus(410)
        ->assertJsonPath('code', 'activation_expired');

    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))
        ->assertStatus(410);
    expect($this->customer->fresh()->isActivated())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Completing the activation
|--------------------------------------------------------------------------
*/

test('Google activation binds the Firebase account and starts a session', function () {
    $token = inviteAndGetToken($this->customer);

    $response = completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', provider: 'google.com'));

    $response->assertOk()
        ->assertJsonPath('data.actor', 'customer')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.customer.activation_status', 'activated')
        ->assertJsonMissingPath('data.customer.firebase_uid');

    $customer = $this->customer->fresh();
    expect($customer->firebase_uid)->toBe('uid-budi')
        ->and($customer->activated_at->toDateTimeString())->toBe('2026-11-02 09:00:00')
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and($customer->activationTokens()->sole()->used_at)->not->toBeNull();

    Mail::assertSent(CustomerActivatedMail::class, fn ($mail) => $mail->hasTo('budi.santoso@gmail.com') && $mail->provider === 'google.com');

    $this->withToken($response->json('data.token'))->getJson('/api/customer/balance')->assertOk();
});

test('email/password activation requires a verified email first', function () {
    $token = inviteAndGetToken($this->customer);

    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', emailVerified: false, provider: 'password'))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');

    // Nothing was bound and the link still works once the email is verified.
    expect($this->customer->fresh()->firebase_uid)->toBeNull();

    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com', emailVerified: true, provider: 'password'))
        ->assertOk();

    Mail::assertSent(CustomerActivatedMail::class, fn ($mail) => $mail->provider === 'password');
});

test('the Firebase email must be the registered one, ignoring case', function () {
    $token = inviteAndGetToken($this->customer);

    completeActivation($token, $this->firebase->issue('uid-other', 'someone.else@gmail.com'))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_mismatch')
        ->assertJsonPath('email', 'b***@gmail.com');

    completeActivation($token, $this->firebase->issue('uid-none', null))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_mismatch');

    expect($this->customer->fresh()->firebase_uid)->toBeNull();

    completeActivation($token, $this->firebase->issue('uid-budi', 'Budi.Santoso@Gmail.com'))->assertOk();
});

test('a link can be used only once', function () {
    $token = inviteAndGetToken($this->customer);
    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertOk();

    completeActivation($token, $this->firebase->issue('uid-attacker', 'budi.santoso@gmail.com'))
        ->assertNotFound()
        ->assertJsonPath('code', 'activation_invalid');

    expect($this->customer->fresh()->firebase_uid)->toBe('uid-budi');
});

test('an invalid Firebase token does not use up the link', function () {
    $token = inviteAndGetToken($this->customer);

    completeActivation($token, 'forged-token')->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    $revoked = $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com');
    $this->firebase->revoke($revoked);
    completeActivation($token, $revoked)->assertUnauthorized();

    $this->postJson('/api/customer/activation/inspect', ['token' => $token])->assertOk();
});

test('an invalid link is reported before the Firebase token is looked at', function () {
    completeActivation(str_repeat('b', 64), 'forged-token')->assertNotFound()->assertJsonPath('code', 'activation_invalid');
});

test('a Firebase account already bound to another customer cannot claim a second account', function () {
    Customer::factory()->activated('uid-budi')->create(['email' => 'budi.lama@gmail.com']);
    $token = inviteAndGetToken($this->customer);

    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'identity_already_linked');

    expect($this->customer->fresh()->isActivated())->toBeFalse();
});

test('a closed account cannot be activated or invited', function () {
    $token = inviteAndGetToken($this->customer);
    actingAsStaff(UserRoles::ADMIN);
    $this->postJson("/api/admin/customers/{$this->customer->id}/deactivate")->assertOk();

    completeActivation($token, $this->firebase->issue('uid-budi', 'budi.santoso@gmail.com'))->assertNotFound();
    $this->postJson("/api/admin/customers/{$this->customer->id}/invitation")->assertNotFound();
    expect(Customer::withTrashed()->find($this->customer->id)->firebase_uid)->toBeNull();
});

test('an account that was claimed meanwhile is not claimed again (race)', function () {
    $token = inviteAndGetToken($this->customer);

    // Another request activated the account after this link was issued, and
    // its link revocation has not been seen: the locked re-check must refuse.
    DB::table('customers')->where('id', $this->customer->id)->update([
        'firebase_uid' => 'uid-first', 'activated_at' => now(), 'email_verified_at' => now(),
    ]);

    completeActivation($token, $this->firebase->issue('uid-second', 'budi.santoso@gmail.com'))->assertNotFound();

    expect($this->customer->fresh()->firebase_uid)->toBe('uid-first')
        ->and(CustomerActivationToken::whereNotNull('used_at')->count())->toBe(0);
});

test('the activation endpoints are rate limited per IP', function () {
    foreach (range(1, 20) as $attempt) {
        $this->postJson('/api/customer/activation/inspect', ['token' => str_repeat('c', 64)])->assertNotFound();
    }

    $this->postJson('/api/customer/activation/inspect', ['token' => str_repeat('c', 64)])->assertTooManyRequests();
});

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

test('existing accounts without an email keep working at the counter', function () {
    $legacy = Customer::factory()->create(['email' => null, 'nis' => '20250001', 'account_number' => 'RPL-2025-001']);
    $teller = actingAsStaff(UserRoles::TELLER);
    openShiftFor($teller);

    $this->getJson('/api/teller/customers/lookup?identifier=RPL-2025-001')->assertOk();
    $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2025-001', 'amount' => 20000])->assertCreated();
    expect($legacy->fresh()->balance)->toBe(20000);
});
