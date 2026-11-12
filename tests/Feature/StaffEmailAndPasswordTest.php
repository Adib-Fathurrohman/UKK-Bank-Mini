<?php

use App\Enums\UserRoles;
use App\Models\User;
use App\Notifications\ResetStaffPassword;
use App\Notifications\StaffPasswordChanged;
use App\Notifications\VerifyStaffEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
| Staff email (optional, verified by a link) and password: forgot/reset by
| email only to a verified address, change with the current password.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 09:00:00'));
    config(['bank.staff_web_url' => 'https://bank.rumput.tech/api']);
    Notification::fake();
    $this->admin = User::factory()->create(['role' => UserRoles::ADMIN, 'username' => 'admin', 'password' => 'admin-lama-123']);
    $this->teller = User::factory()->teller()->create(['username' => 'rina', 'password' => 'rina-lama-123']);
});

function verificationLink(User $user): string
{
    $link = null;
    Notification::assertSentTo($user, VerifyStaffEmail::class, function (VerifyStaffEmail $notification) use ($user, &$link) {
        preg_match('/https?:\/\/\S+/', $notification->toMail($user)->actionUrl, $match);
        $link = $match[0];

        return true;
    });

    return $link;
}

function resetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetStaffPassword::class, function (ResetStaffPassword $notification) use ($user, &$token) {
        parse_str(parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];

        return true;
    });

    return $token;
}

function verifiedTeller(): User
{
    $teller = test()->teller;
    $teller->forceFill(['email' => 'rina@sekolah.sch.id', 'email_verified_at' => now()])->save();

    return $teller;
}

/*
|--------------------------------------------------------------------------
| Email and verification
|--------------------------------------------------------------------------
*/

test('an administrator gives a staff member an email, which must then be verified', function () {
    Sanctum::actingAs($this->admin);

    $this->patchJson("/api/admin/staff/{$this->teller->id}", ['email' => 'Rina@Sekolah.sch.id'])
        ->assertOk()
        ->assertJsonPath('data.email', 'rina@sekolah.sch.id')
        ->assertJsonPath('data.email_verified', false);

    $link = verificationLink($this->teller->fresh());
    $this->get($link)->assertRedirect('https://bank.rumput.tech/api/login?email_verified=1');

    expect($this->teller->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('new staff can be created with an email', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/admin/staff', [
        'username' => 'dodi', 'full_name' => 'Dodi', 'password' => 'rahasia123', 'role' => 'teller', 'email' => 'dodi@sekolah.sch.id',
    ])->assertCreated()->assertJsonPath('data.email', 'dodi@sekolah.sch.id')->assertJsonPath('data.email_verified', false);

    Notification::assertSentTo(User::where('username', 'dodi')->first(), VerifyStaffEmail::class);
});

test('emails are unique and valid', function () {
    verifiedTeller();
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/admin/staff', ['username' => 'dodi', 'full_name' => 'Dodi', 'password' => 'rahasia123', 'role' => 'teller', 'email' => 'rina@sekolah.sch.id'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/admin/staff', ['username' => 'dodi', 'full_name' => 'Dodi', 'password' => 'rahasia123', 'role' => 'teller', 'email' => 'bukan-email'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('administrators and staff set their own email with their password', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson('/api/staff/email', ['email' => 'admin@sekolah.sch.id', 'current_password' => 'salah'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->putJson('/api/staff/email', ['email' => 'admin@sekolah.sch.id', 'current_password' => 'admin-lama-123'])
        ->assertOk()->assertJsonPath('data.email', 'admin@sekolah.sch.id');

    Notification::assertSentTo($this->admin, VerifyStaffEmail::class);
    $this->getJson('/api/staff/me')->assertJsonPath('data.email_verified', false);
});

test('changing the email clears the verification, and an old link no longer verifies', function () {
    Sanctum::actingAs($this->admin);
    $this->patchJson("/api/admin/staff/{$this->teller->id}", ['email' => 'rina@sekolah.sch.id'])->assertOk();
    $oldLink = verificationLink($this->teller->fresh());

    $this->patchJson("/api/admin/staff/{$this->teller->id}", ['email' => 'rina.baru@sekolah.sch.id'])->assertOk();

    $this->get($oldLink)->assertRedirect('https://bank.rumput.tech/api/login?email_verified=0');
    expect($this->teller->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a tampered or expired verification link is refused', function () {
    Sanctum::actingAs($this->admin);
    $this->patchJson("/api/admin/staff/{$this->teller->id}", ['email' => 'rina@sekolah.sch.id'])->assertOk();
    $link = verificationLink($this->teller->fresh());

    $this->get(str_replace("/verify/{$this->teller->id}/", "/verify/{$this->admin->id}/", $link))->assertForbidden();

    $this->travel(25)->hours();
    $this->get($link)->assertForbidden();

    expect($this->teller->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the verification link can be sent again until the email is verified', function () {
    Sanctum::actingAs($this->teller);
    $this->postJson('/api/staff/email/verification-notification')->assertUnprocessable()->assertJsonPath('code', 'email_missing');

    $this->teller->forceFill(['email' => 'rina@sekolah.sch.id'])->save();
    $this->postJson('/api/staff/email/verification-notification')->assertStatus(202);
    Notification::assertSentTo($this->teller, VerifyStaffEmail::class);

    $this->teller->forceFill(['email_verified_at' => now()])->save();
    $this->postJson('/api/staff/email/verification-notification')->assertConflict()->assertJsonPath('code', 'email_already_verified');
});

/*
|--------------------------------------------------------------------------
| Forgot and reset
|--------------------------------------------------------------------------
*/

test('forgot password sends a link to a verified email and the token sets a new password', function () {
    $teller = verifiedTeller();
    $teller->createToken('laptop');

    $this->postJson('/api/staff/password/forgot', ['email' => 'RINA@sekolah.sch.id'])->assertStatus(202);

    $token = resetToken($teller);
    Notification::assertSentTo($teller, ResetStaffPassword::class, fn ($n) => str_starts_with($n->toMail($teller)->actionUrl, 'https://bank.rumput.tech/api/reset-password?token='));

    $this->postJson('/api/staff/password/reset', ['email' => 'rina@sekolah.sch.id', 'token' => $token, 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertOk();

    expect(Hash::check('rina-baru-456', $teller->fresh()->password))->toBeTrue()
        ->and($teller->tokens()->count())->toBe(0);
    Notification::assertSentTo($teller, StaffPasswordChanged::class);

    $this->postJson('/api/login', ['username' => 'rina', 'password' => 'rina-baru-456'])->assertOk();

    // One use only.
    $this->postJson('/api/staff/password/reset', ['email' => 'rina@sekolah.sch.id', 'token' => $token, 'password' => 'lagi-lagi-789', 'password_confirmation' => 'lagi-lagi-789'])
        ->assertUnprocessable()->assertJsonPath('code', 'password_reset_invalid');
});

test('forgot password answers the same and sends nothing for unknown, unverified or blocked accounts', function () {
    $this->teller->forceFill(['email' => 'rina@sekolah.sch.id'])->save(); // unverified
    $blocked = User::factory()->teller()->create(['email' => 'blok@sekolah.sch.id', 'email_verified_at' => now(), 'is_active' => false]);

    foreach (['tidak-ada@sekolah.sch.id', 'rina@sekolah.sch.id', 'blok@sekolah.sch.id'] as $email) {
        $this->postJson('/api/staff/password/forgot', ['email' => $email])
            ->assertStatus(202)
            ->assertJsonPath('message', fn ($message) => str_starts_with($message, 'Jika email tersebut terdaftar'));
    }

    Notification::assertNothingSent();
});

test('a reset link expires after 60 minutes', function () {
    $teller = verifiedTeller();
    $this->postJson('/api/staff/password/forgot', ['email' => 'rina@sekolah.sch.id']);
    $token = resetToken($teller);

    $this->travel(61)->minutes();

    $this->postJson('/api/staff/password/reset', ['email' => 'rina@sekolah.sch.id', 'token' => $token, 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertUnprocessable()->assertJsonPath('code', 'password_reset_invalid');
    expect(Hash::check('rina-lama-123', $teller->fresh()->password))->toBeTrue();
});

test('a reset needs a valid token for that email and a confirmed password', function () {
    $teller = verifiedTeller();
    $this->postJson('/api/staff/password/forgot', ['email' => 'rina@sekolah.sch.id']);
    $token = resetToken($teller);
    User::factory()->teller()->create(['email' => 'lain@sekolah.sch.id', 'email_verified_at' => now()]);

    $this->postJson('/api/staff/password/reset', ['email' => 'lain@sekolah.sch.id', 'token' => $token, 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertUnprocessable()->assertJsonPath('code', 'password_reset_invalid');
    $this->postJson('/api/staff/password/reset', ['email' => 'rina@sekolah.sch.id', 'token' => 'palsu', 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertUnprocessable()->assertJsonPath('code', 'password_reset_invalid');
    $this->postJson('/api/staff/password/reset', ['email' => 'rina@sekolah.sch.id', 'token' => $token, 'password' => 'pendek', 'password_confirmation' => 'pendek'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Hash::check('rina-lama-123', $teller->fresh()->password))->toBeTrue();
});

test('only one reset email per minute', function () {
    $teller = verifiedTeller();

    $this->postJson('/api/staff/password/forgot', ['email' => 'rina@sekolah.sch.id']);
    $this->postJson('/api/staff/password/forgot', ['email' => 'rina@sekolah.sch.id']);

    Notification::assertSentToTimes($teller, ResetStaffPassword::class, 1);
});

test('forgot password is rate limited', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/staff/password/forgot', ['email' => 'x@sekolah.sch.id'])->assertStatus(202);
    }
    $this->postJson('/api/staff/password/forgot', ['email' => 'x@sekolah.sch.id'])->assertTooManyRequests();
});

test('a mail server outage does not change the answer', function () {
    Notification::swap(new class extends \Illuminate\Support\Testing\Fakes\NotificationFake
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('SMTP down');
        }
    });
    verifiedTeller();

    $this->postJson('/api/staff/password/forgot', ['email' => 'rina@sekolah.sch.id'])->assertStatus(202);
});

/*
|--------------------------------------------------------------------------
| Change password while logged in
|--------------------------------------------------------------------------
*/

test('a logged-in staff member changes the password and other sessions end', function () {
    $teller = verifiedTeller();
    $other = $teller->createToken('hp')->accessToken;
    $current = $teller->createToken('laptop');

    $this->withToken($current->plainTextToken)
        ->postJson('/api/staff/password', ['current_password' => 'salah', 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertUnprocessable()->assertJsonPath('code', 'current_password_invalid');

    $this->withToken($current->plainTextToken)
        ->postJson('/api/staff/password', ['current_password' => 'rina-lama-123', 'password' => 'rina-baru-456', 'password_confirmation' => 'rina-baru-456'])
        ->assertOk();

    expect(Hash::check('rina-baru-456', $teller->fresh()->password))->toBeTrue()
        ->and($teller->tokens()->pluck('id')->all())->toBe([$current->accessToken->id])
        ->and($teller->tokens()->whereKey($other->id)->exists())->toBeFalse();
    Notification::assertSentTo($teller, StaffPasswordChanged::class);
});
