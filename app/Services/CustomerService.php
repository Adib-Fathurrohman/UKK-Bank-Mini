<?php

namespace App\Services;

use App\Contracts\FirebaseAuth;
use App\Enums\AccountStatus;
use App\Exceptions\CustomerAuthException;
use App\Models\Customer;
use App\Models\Major;
use App\Support\SchoolYear;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    public function __construct(
        private FirebaseAuth $firebase,
        private CustomerActivationService $activation,
    ) {
    }

    /**
     * Open a new savings account with a generated account number.
     */
    public function create(array $data): Customer
    {
        $major = Major::findOrFail($data['major_id']);

        // Two admins creating accounts at the same moment could compute the same
        // next number; the unique index catches it and we simply try again.
        return retry(3, fn () => Customer::create([
            ...$data,
            'account_number' => $this->nextAccountNumber($major, now()),
            'account_status' => AccountStatus::ACTIVE,
            'balance' => 0,
        ]), when: fn ($e) => $e instanceof UniqueConstraintViolationException
            && str_contains($e->getMessage(), 'account_number'));
    }

    /**
     * Close the account of a graduated student (FR-Rek-03).
     *
     * The row is kept (soft delete) so its transactions and journals stay intact.
     * All app sessions and invitation links end.
     */
    public function deactivate(Customer $customer): Customer
    {
        DB::transaction(function () use ($customer) {
            $customer->update(['account_status' => AccountStatus::INACTIVE]);
            $customer->tokens()->delete();
            $this->activation->revokeOutstanding($customer);
            $customer->delete();
        });

        // Also stop the Firebase account from getting new ID tokens. The backend
        // refuses closed accounts anyway, so a Firebase outage must not undo
        // or block the deactivation; it is logged for a manual follow-up.
        if ($customer->firebase_uid) {
            try {
                $this->firebase->disableUser($customer->firebase_uid);
            } catch (CustomerAuthException) {
                Log::warning('Could not disable the Firebase user of a deactivated customer.', ['customer_id' => $customer->id]);
            }
        }

        return $customer;
    }

    /**
     * Account numbers follow "{major code}-{school year}-{serial}", e.g. RPL-2026-001:
     * the major's code, the school year in which the account is opened, and a
     * 3-digit serial that restarts for every major and school year. Closed
     * accounts keep their number, so it is never reused.
     */
    public function nextAccountNumber(Major $major, CarbonInterface $openedAt): string
    {
        $prefix = $major->code.'-'.SchoolYear::startYear($openedAt).'-';

        $lastSerial = Customer::withTrashed()
            ->where('account_number', 'like', $prefix.'%')
            ->pluck('account_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($lastSerial + 1), 3, '0', STR_PAD_LEFT);
    }
}
