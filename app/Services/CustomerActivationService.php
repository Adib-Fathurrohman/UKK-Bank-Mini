<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\CustomerAuthException;
use App\Mail\CustomerActivatedMail;
use App\Mail\CustomerInvitationMail;
use App\Models\Customer;
use App\Models\CustomerActivationToken;
use App\Models\User;
use App\Support\Email;
use App\Support\FirebaseIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;
use Throwable;

/**
 * First-time activation: the administrator's invitation and the customer
 * claiming their account with a Firebase identity.
 *
 * Invitation tokens are 256-bit random values. Only their SHA-256 hash is
 * stored; issuing a new one revokes the previous ones; a token can be used
 * once and only before it expires. Activation needs both the token (proof of
 * the invitation email) and a Firebase ID token whose verified email equals
 * the registered one, and binds the Firebase UID to the account exactly once.
 */
class CustomerActivationService
{
    /**
     * Send an invitation (or a new one) to the customer's registered email.
     *
     * The token is committed before the email is sent; if sending fails the
     * invitation still exists and the administrator can resend it.
     *
     * @param  User|null  $issuer  the administrator, or null when the customer asked for a new link
     * @return array{sent_to: string, expires_at: Carbon, mail_sent: bool}
     */
    public function invite(Customer $customer, ?User $issuer = null): array
    {
        $plainToken = bin2hex(random_bytes(32));

        [$record, $isResend] = DB::transaction(function () use ($customer, $issuer, $plainToken) {
            // Serialize invitations for the same customer, then check the fresh row.
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->first();

            $this->ensureInvitable($customer);

            $isResend = $customer->activationTokens()->exists();

            // Only the newest link works.
            $customer->activationTokens()->outstanding()->update(['revoked_at' => now()]);

            $record = $customer->activationTokens()->create([
                'token_hash' => CustomerActivationToken::hash($plainToken),
                'email' => $customer->email,
                'issued_by' => $issuer?->getKey(),
                'expires_at' => now()->addHours(config('bank.activation_token_hours')),
            ]);

            return [$record, $isResend];
        });

        $mailSent = $this->send($record->email, new CustomerInvitationMail(
            customer: $customer,
            activationUrl: $this->activationUrl($plainToken),
            expiresAt: $record->expires_at,
            isResend: $isResend,
        ));

        return [
            'sent_to' => $record->email,
            'expires_at' => $record->expires_at,
            'mail_sent' => $mailSent,
        ];
    }

    /**
     * The usable invitation for a link, for the activation page.
     *
     * @throws CustomerAuthException activation_invalid or activation_expired
     */
    public function inspect(#[SensitiveParameter] string $plainToken): CustomerActivationToken
    {
        $record = CustomerActivationToken::with('customer')
            ->where('token_hash', CustomerActivationToken::hash($plainToken))
            ->first();

        $this->ensureUsable($record, $record?->customer);

        return $record;
    }

    /**
     * Bind a verified Firebase identity to the invited customer.
     *
     * Everything is re-checked inside one transaction with the token and the
     * customer rows locked, so two concurrent requests cannot both claim the
     * account, and a link cannot be used twice.
     *
     * @throws CustomerAuthException
     */
    public function activate(#[SensitiveParameter] string $plainToken, FirebaseIdentity $identity): Customer
    {
        $customer = DB::transaction(function () use ($plainToken, $identity) {
            $record = CustomerActivationToken::where('token_hash', CustomerActivationToken::hash($plainToken))
                ->lockForUpdate()
                ->first();

            $customer = $record
                ? Customer::whereKey($record->customer_id)->lockForUpdate()->first()
                : null;

            $this->ensureUsable($record, $customer);

            if (! $identity->emailVerified) {
                throw CustomerAuthException::emailNotVerified();
            }

            // The verified email must be the one the school registered and the link was sent to.
            $email = $identity->normalizedEmail();

            if ($email === null || $email !== Email::normalize($customer->email) || $email !== Email::normalize($record->email)) {
                throw CustomerAuthException::emailMismatch($customer->email);
            }

            if (Customer::withTrashed()->where('firebase_uid', $identity->uid)->exists()) {
                throw CustomerAuthException::identityAlreadyLinked();
            }

            try {
                $customer->update([
                    'firebase_uid' => $identity->uid,
                    'email_verified_at' => now(),
                    'activated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another account was bound to the same UID at the same moment.
                throw CustomerAuthException::identityAlreadyLinked();
            }

            $record->update(['used_at' => now()]);
            $customer->activationTokens()->outstanding()->update(['revoked_at' => now()]);

            return $customer;
        });

        $this->send($customer->email, new CustomerActivatedMail($customer, $identity->provider));

        return $customer;
    }

    /**
     * Revoke every outstanding link, e.g. when the email changes or the account is closed.
     */
    public function revokeOutstanding(Customer $customer): void
    {
        $customer->activationTokens()->outstanding()->update(['revoked_at' => now()]);
    }

    private function ensureInvitable(?Customer $customer): void
    {
        if (! $customer || $customer->account_status === AccountStatus::INACTIVE) {
            throw CustomerAuthException::accountInactive();
        }

        if ($customer->isActivated()) {
            throw CustomerAuthException::alreadyActivated();
        }

        if (! $customer->email) {
            throw CustomerAuthException::emailMissing();
        }
    }

    /**
     * Unknown, used, revoked, or for an account that is closed or already
     * activated: "invalid". Otherwise only an expired link gets its own answer,
     * so the page can offer to request a new one.
     */
    private function ensureUsable(?CustomerActivationToken $record, ?Customer $customer): void
    {
        if (! $record || $record->used_at || $record->revoked_at
            || ! $customer || $customer->trashed() || $customer->isActivated()
            || $customer->account_status === AccountStatus::INACTIVE) {
            throw CustomerAuthException::activationInvalid();
        }

        if ($record->isExpired()) {
            throw CustomerAuthException::activationExpired();
        }
    }

    private function activationUrl(string $plainToken): string
    {
        return config('bank.customer_activation_url').'?token='.$plainToken;
    }

    /**
     * Emails are sent synchronously; a failure is reported and returned
     * instead of undoing the database change.
     */
    private function send(string $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
