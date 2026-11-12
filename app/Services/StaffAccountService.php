<?php

namespace App\Services;

use App\Exceptions\StaffPasswordException;
use App\Models\User;
use App\Notifications\ResetStaffPassword;
use App\Notifications\StaffPasswordChanged;
use App\Notifications\VerifyStaffEmail;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;
use Throwable;

/**
 * Staff email and password.
 *
 * - The email is optional and must be verified (link sent to it) before it can
 *   be used to reset the password. Changing it clears the verification.
 * - Forgot password sends a one-time link (Laravel's password broker: token
 *   stored hashed, valid auth.passwords.users.expire minutes) only to an active
 *   staff member's verified email; the API answers the same either way.
 * - Resetting or changing the password revokes the other sessions and tells
 *   the staff member by email.
 *
 * Mail failures are logged, never shown to the caller: the reset page must not
 * behave differently for existing emails, and saving an email must not fail
 * because the mail server is down (the link can be sent again).
 */
class StaffAccountService
{
    /**
     * Set or change the email. A new address starts unverified and gets a link.
     */
    public function assignEmail(User $staff, ?string $email): User
    {
        $email = $email === null || $email === '' ? null : strtolower(trim($email));

        if ($email === $staff->email) {
            return $staff;
        }

        $staff->forceFill(['email' => $email, 'email_verified_at' => null])->save();

        if ($email !== null) {
            $this->send($staff, new VerifyStaffEmail);
        }

        return $staff;
    }

    public function resendVerification(User $staff): void
    {
        if ($staff->email === null) {
            throw StaffPasswordException::noEmail();
        }
        if ($staff->hasVerifiedEmail()) {
            throw StaffPasswordException::alreadyVerified();
        }

        $this->send($staff, new VerifyStaffEmail);
    }

    /**
     * The signed link was opened. False when it belongs to an older address.
     */
    public function verifyEmail(User $staff, string $hash): bool
    {
        if ($staff->email === null || ! hash_equals(sha1(strtolower($staff->email)), $hash)) {
            return false;
        }

        if (! $staff->hasVerifiedEmail()) {
            $staff->forceFill(['email_verified_at' => now()])->save();
        }

        return true;
    }

    /**
     * Send a reset link if the email belongs to an active staff member and is verified.
     * Silent otherwise, and at most one link per throttle window (60 s).
     */
    public function sendResetLink(string $email): void
    {
        $staff = $this->resettable($email);

        if (! $staff || $this->tokens()->recentlyCreatedToken($staff)) {
            return;
        }

        $this->send($staff, new ResetStaffPassword($this->tokens()->create($staff)));
    }

    public function resetPassword(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): User
    {
        $staff = $this->resettable($email);

        if (! $staff || ! $this->tokens()->exists($staff, $token)) {
            throw StaffPasswordException::invalidReset();
        }

        DB::transaction(function () use ($staff, $password) {
            $staff->forceFill(['password' => $password, 'remember_token' => null])->save();
            $staff->tokens()->delete();
            $this->tokens()->delete($staff);
        });

        $this->send($staff, new StaffPasswordChanged);

        return $staff;
    }

    /**
     * Logged-in change. Sessions other than the current one are revoked.
     */
    public function changePassword(User $staff, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, ?int $keepTokenId): void
    {
        if (! Hash::check($current, $staff->password)) {
            throw StaffPasswordException::wrongPassword();
        }

        DB::transaction(function () use ($staff, $new, $keepTokenId) {
            $staff->forceFill(['password' => $new])->save();
            $staff->tokens()->when($keepTokenId, fn ($query) => $query->whereKeyNot($keepTokenId))->delete();
        });

        if ($staff->hasVerifiedEmail()) {
            $this->send($staff, new StaffPasswordChanged);
        }
    }

    private function resettable(string $email): ?User
    {
        $staff = User::where('email', strtolower(trim($email)))->first();

        return $staff && $staff->is_active && $staff->hasVerifiedEmail() ? $staff : null;
    }

    private function tokens(): TokenRepositoryInterface
    {
        return Password::broker('users')->getRepository();
    }

    private function send(User $staff, Notification $notification): void
    {
        try {
            $staff->notify($notification);
        } catch (Throwable $e) {
            Log::error('Could not send a staff email.', ['user_id' => $staff->id, 'notification' => $notification::class, 'error' => $e->getMessage()]);
        }
    }
}
