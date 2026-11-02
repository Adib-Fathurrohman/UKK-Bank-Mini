<?php

namespace App\Services;

use App\Contracts\FirebaseAuth;
use App\Enums\AccountStatus;
use App\Exceptions\CustomerAuthException;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use SensitiveParameter;

/**
 * How a customer gets into the app.
 *
 * - A session (Sanctum token) is only started after a verified Firebase login
 *   or activation, and lasts CUSTOMER_SESSION_MINUTES.
 * - After activation the customer creates their PIN, re-authenticating with
 *   Firebase. Inside a session the PIN unlocks the app and authorizes
 *   withdrawals; the PIN alone never starts a session.
 */
class CustomerAuthService
{
    public function __construct(private FirebaseAuth $firebase)
    {
    }

    /**
     * The customer for a verified Firebase ID token. The customer is found by
     * the token's UID only; the email in the token is never used to pick an
     * account.
     */
    public function customerForFirebaseLogin(#[SensitiveParameter] string $idToken): Customer
    {
        $identity = $this->firebase->verifyIdToken($idToken);

        $customer = Customer::withTrashed()->where('firebase_uid', $identity->uid)->first();

        if (! $customer || ! $customer->isActivated()) {
            throw CustomerAuthException::accountNotLinked();
        }

        if ($customer->trashed() || $customer->account_status === AccountStatus::INACTIVE) {
            throw CustomerAuthException::accountInactive();
        }

        if (! $identity->emailVerified) {
            throw CustomerAuthException::emailNotVerified();
        }

        return $customer;
    }

    /**
     * Re-authentication for sensitive changes (creating the PIN): a fresh
     * Firebase ID token of the account bound to this customer.
     */
    public function verifyReauthentication(Customer $customer, #[SensitiveParameter] string $idToken): void
    {
        $identity = $this->firebase->verifyIdToken($idToken);

        if (! $customer->firebase_uid || ! hash_equals($customer->firebase_uid, $identity->uid)) {
            throw CustomerAuthException::identityMismatch();
        }
    }

    /**
     * Issue a customer session token. Suspended accounts may log in to view
     * their account, as before; closed accounts never reach this point.
     *
     * @return array{actor: string, token: string, token_type: string, expires_at: \Illuminate\Support\Carbon, customer: CustomerResource}
     */
    public function startSession(Customer $customer): array
    {
        $expiresAt = now()->addMinutes(config('bank.customer_session_minutes'));

        return [
            'actor' => 'customer',
            'token' => $customer->createToken('customer-portal', ['*'], $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'customer' => new CustomerResource($customer->load('major')),
        ];
    }
}
