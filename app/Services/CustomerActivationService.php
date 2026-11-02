<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\CustomerAuthException;
use App\Models\Customer;
use App\Support\Email;
use App\Support\FirebaseIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * First-time activation: the customer claims their account in the app with a
 * Firebase identity.
 *
 * No email is sent by the backend. The proof of the mailbox is the Firebase
 * verified email (Google accounts are verified; for email/password Firebase
 * sends its own verification email), and it must equal the email the
 * administrator registered for the NIS the customer enters. The Firebase UID
 * is then bound to the account exactly once.
 */
class CustomerActivationService
{
    /**
     * Bind a verified Firebase identity to the customer with this NIS.
     *
     * Everything is checked inside one transaction with the customer row
     * locked, so two concurrent requests cannot both claim the account.
     *
     * @throws CustomerAuthException
     */
    public function activate(string $nis, FirebaseIdentity $identity): Customer
    {
        if (! $identity->emailVerified) {
            throw CustomerAuthException::emailNotVerified();
        }

        return DB::transaction(function () use ($nis, $identity) {
            $customer = Customer::withTrashed()->where('nis', $nis)->lockForUpdate()->first();
            $email = $identity->normalizedEmail();

            // Unknown NIS and another person's email get the same answer.
            if (! $customer || ! $customer->email || $email === null || $email !== Email::normalize($customer->email)) {
                throw CustomerAuthException::activationMismatch();
            }

            if ($customer->trashed() || $customer->account_status === AccountStatus::INACTIVE) {
                throw CustomerAuthException::accountInactive();
            }

            if ($customer->isActivated()) {
                // Repeating the activation with the same account just signs in again.
                if ($customer->firebase_uid === $identity->uid) {
                    return $customer;
                }

                throw CustomerAuthException::alreadyActivated();
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

            return $customer;
        });
    }
}
