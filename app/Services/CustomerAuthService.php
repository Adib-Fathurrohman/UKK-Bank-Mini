<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\InvalidPinException;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * How a customer gets into the app: NIS or account number + PIN.
 *
 * - The first PIN is a temporary one from the system. A session started with
 *   it can only replace the PIN (EnsurePinChanged) until the customer has.
 * - Sessions last CUSTOMER_SESSION_MINUTES; inside one the PIN unlocks the
 *   app and authorizes withdrawals.
 */
class CustomerAuthService
{
    public function __construct(private PinService $pins)
    {
    }

    /**
     * Find the customer by NIS or account number and verify the PIN.
     *
     * Wrong PINs count toward the shared PIN lock. Unknown identifier, wrong
     * PIN and closed account all get the same message; only an actual lock is
     * revealed (422 on "identifier").
     */
    public function verifyCredentials(string $identifier, #[SensitiveParameter] string $pin): Customer
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

        return $customer->refresh();
    }

    /**
     * Issue a customer session token. Suspended accounts may log in to view
     * their account; closed accounts never reach this point.
     *
     * @return array{actor: string, token: string, token_type: string, expires_at: \Illuminate\Support\Carbon, must_change_pin: bool, customer: CustomerResource}
     */
    public function startSession(Customer $customer): array
    {
        $expiresAt = now()->addMinutes(config('bank.customer_session_minutes'));

        return [
            'actor' => 'customer',
            'token' => $customer->createToken('customer-portal', ['*'], $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'must_change_pin' => $customer->pin_must_change,
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
