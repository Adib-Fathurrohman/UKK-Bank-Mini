<?php

namespace App\Services;

use App\Contracts\FirebaseAuth;
use App\Enums\AccountStatus;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\InvalidPinException;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * How a customer gets into the app.
 *
 * - A session (Sanctum token) is only started after a verified Firebase login
 *   or activation, and lasts CUSTOMER_SESSION_MINUTES.
 * - Inside a session the PIN unlocks the app and authorizes withdrawals.
 * - NIS/account number + PIN alone never starts a session: it only tells the
 *   app whether the account still has to be activated.
 */
class CustomerAuthService
{
    public function __construct(
        private FirebaseAuth $firebase,
        private PinService $pins,
    ) {
    }

    /**
     * Find the customer by NIS or account number and verify the PIN.
     *
     * Wrong PINs count toward the shared PIN lock. The messages are the ones
     * the NIS + PIN login has always returned (422 on "identifier").
     */
    public function verifyPinCredentials(string $identifier, #[SensitiveParameter] string $pin): Customer
    {
        // Same precedence as the teller lookup: account number first, then NIS.
        $customer = Customer::where('account_number', $identifier)->first()
            ?? Customer::where('nis', $identifier)->first();

        if (! $customer) {
            throw $this->wrongCredentials();
        }

        try {
            $this->pins->verify($customer, $pin);
        } catch (InvalidPinException $e) {
            // Remaining attempts are not revealed here; only an actual lock is.
            throw $e->lockedUntil
                ? ValidationException::withMessages([
                    'identifier' => 'Terlalu banyak percobaan PIN salah. Coba lagi pukul '.$e->lockedUntil->format('H:i').'.',
                ])
                : $this->wrongCredentials();
        }

        if ($customer->account_status === AccountStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'identifier' => 'Rekening ini sudah tidak aktif.',
            ]);
        }

        return $customer;
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

    private function wrongCredentials(): ValidationException
    {
        return ValidationException::withMessages([
            'identifier' => 'NIS/nomor rekening atau PIN salah.',
        ]);
    }
}
