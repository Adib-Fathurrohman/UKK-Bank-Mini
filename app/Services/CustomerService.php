<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Customer;
use App\Models\Major;
use App\Support\SchoolYear;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(private PinService $pins)
    {
    }

    /**
     * Open a new savings account with a generated account number and a
     * temporary PIN, which the customer must replace at the first login.
     *
     * @param  string|null  $temporaryPin  only for demo data; normally generated
     * @return array{0: Customer, 1: string} the account and its temporary PIN (shown once)
     */
    public function create(array $data, ?string $temporaryPin = null): array
    {
        $major = Major::findOrFail($data['major_id']);
        $temporaryPin ??= $this->pins->temporaryPin();

        // Two admins creating accounts at the same moment could compute the same
        // next number; the unique index catches it and we simply try again.
        $customer = retry(3, fn () => Customer::create([
            ...$data,
            'account_number' => $this->nextAccountNumber($major, now()),
            'account_status' => AccountStatus::ACTIVE,
            'balance' => 0,
            'pin' => $temporaryPin,
            'pin_must_change' => true,
        ]), when: fn ($e) => $e instanceof UniqueConstraintViolationException
            && str_contains($e->getMessage(), 'account_number'));

        return [$customer, $temporaryPin];
    }

    /**
     * Close the account of a graduated student (FR-Rek-03).
     *
     * The row is kept (soft delete) so its transactions and journals stay intact.
     * All app sessions end, and with them the push tokens.
     */
    public function deactivate(Customer $customer): Customer
    {
        DB::transaction(function () use ($customer) {
            $customer->update(['account_status' => AccountStatus::INACTIVE]);
            $customer->tokens()->delete();
            $customer->delete();
        });

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
