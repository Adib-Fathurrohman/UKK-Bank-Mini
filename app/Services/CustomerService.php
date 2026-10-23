<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    /**
     * Open a new savings account with a generated account number.
     */
    public function create(array $data): Customer
    {
        // Two admins creating accounts at the same moment could compute the same
        // next number; the unique index catches it and we simply try again.
        return retry(3, fn () => Customer::create([
            ...$data,
            'account_number' => $this->nextAccountNumber(),
            'account_status' => AccountStatus::ACTIVE,
            'balance' => 0,
        ]), when: fn ($e) => $e instanceof UniqueConstraintViolationException
            && str_contains($e->getMessage(), 'account_number'));
    }

    /**
     * Close the account of a graduated student (FR-Rek-03).
     *
     * The row is kept (soft delete) so its transactions and journals stay intact.
     */
    public function deactivate(Customer $customer): Customer
    {
        return DB::transaction(function () use ($customer) {
            $customer->update(['account_status' => AccountStatus::INACTIVE]);
            $customer->tokens()->delete();
            $customer->delete();

            return $customer;
        });
    }

    /**
     * Account numbers are 10 digits and sequential, including closed accounts.
     */
    public function nextAccountNumber(): string
    {
        $last = Customer::withTrashed()->max('account_number');

        return $last ? (string) ((int) $last + 1) : '1000000001';
    }
}
