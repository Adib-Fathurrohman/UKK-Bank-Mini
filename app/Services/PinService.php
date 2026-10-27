<?php

namespace App\Services;

use App\Exceptions\InvalidPinException;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a customer's 6-digit PIN (soal 1d: "Otorisasi Keamanan Penarikan").
 *
 * Wrong attempts are counted on the account; after pin_max_attempts in a row
 * the PIN is locked for pin_lock_minutes. The counter is saved in its own
 * database transaction so that it survives the refused request.
 */
class PinService
{
    public function verify(Customer $customer, string $pin): void
    {
        $failure = DB::transaction(function () use ($customer, $pin) {
            $customer = Customer::withTrashed()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($customer->pin_locked_until?->isFuture()) {
                return InvalidPinException::locked($customer->pin_locked_until);
            }

            if (Hash::check($pin, $customer->pin)) {
                if ($customer->pin_failed_attempts > 0 || $customer->pin_locked_until) {
                    $customer->update(['pin_failed_attempts' => 0, 'pin_locked_until' => null]);
                }

                return null;
            }

            $attempts = $customer->pin_failed_attempts + 1;
            $max = config('bank.pin_max_attempts');

            if ($attempts >= $max) {
                $until = now()->addMinutes(config('bank.pin_lock_minutes'));
                $customer->update(['pin_failed_attempts' => 0, 'pin_locked_until' => $until]);

                return InvalidPinException::locked($until);
            }

            $customer->update(['pin_failed_attempts' => $attempts, 'pin_locked_until' => null]);

            return InvalidPinException::wrong($max - $attempts);
        });

        if ($failure) {
            throw $failure;
        }
    }

    /**
     * Set a new PIN after verifying the current one.
     */
    public function change(Customer $customer, string $currentPin, string $newPin): void
    {
        $this->verify($customer, $currentPin);

        $customer->update(['pin' => $newPin]);
    }
}
