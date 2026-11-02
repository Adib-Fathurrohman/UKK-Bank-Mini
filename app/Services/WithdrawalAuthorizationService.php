<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\WithdrawalAuthorizationStatus as Status;
use App\Exceptions\AccountNotActiveException;
use App\Exceptions\AuthorizationNotUsableException;
use App\Exceptions\CustomerAppNotActivatedException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidPinException;
use App\Models\Customer;
use App\Models\User;
use App\Models\WithdrawalAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Withdrawals authorized by the customer in the mobile app (soal 1d).
 *
 * 1. The teller creates a request (no money moves).
 * 2. The customer reviews it and approves with their PIN, or rejects it.
 * 3. The teller completes it: TransactionService::completeAuthorizedWithdrawal().
 *
 * Every request is bound to one teller, customer, amount and shift, expires,
 * and can produce at most one withdrawal. The PIN only ever travels from the
 * customer's phone to approve(); it is checked by PinService and never stored.
 */
class WithdrawalAuthorizationService
{
    public function __construct(
        private DailyReportService $reports,
        private SettingsService $settings,
        private PinService $pins,
    ) {
    }

    /**
     * Teller starts a withdrawal that the customer must approve.
     */
    public function create(User $teller, Customer $customer, int $amount): WithdrawalAuthorization
    {
        return DB::transaction(function () use ($teller, $customer, $amount) {
            // Same lock order as transactions: teller, shift, customer.
            $shift = $this->reports->shiftForTransaction($teller);

            $this->expireOverdue(WithdrawalAuthorization::where('teller_id', $teller->id));
            if (WithdrawalAuthorization::where('teller_id', $teller->id)->whereIn('status', Status::open())->exists()) {
                throw new AuthorizationNotUsableException('Masih ada permintaan penarikan yang belum selesai. Selesaikan atau batalkan terlebih dahulu.');
            }

            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $this->expireOverdue(WithdrawalAuthorization::where('customer_id', $customer->id));
            if (WithdrawalAuthorization::where('customer_id', $customer->id)->whereIn('status', Status::open())->exists()) {
                throw new AuthorizationNotUsableException('Nasabah ini masih memiliki permintaan penarikan yang belum selesai.');
            }

            if ($customer->account_status !== AccountStatus::ACTIVE) {
                throw new AccountNotActiveException($customer->account_status);
            }

            // Only the app can approve; without it the request could only expire.
            if (! $customer->isActivated()) {
                throw CustomerAppNotActivatedException::forMode($this->settings->allowsTerminalWithdrawalVerification());
            }

            if ($customer->pin_locked_until?->isFuture()) {
                throw InvalidPinException::locked($customer->pin_locked_until);
            }

            $this->ensureBalanceAllows($customer, $amount);

            return WithdrawalAuthorization::create([
                'teller_id' => $teller->id,
                'customer_id' => $customer->id,
                'daily_report_id' => $shift->id,
                'amount' => $amount,
                'status' => Status::PENDING,
                'expires_at' => now()->addSeconds(config('bank.withdrawal_authorization_pending_seconds')),
            ]);
        });
    }

    /**
     * Customer approves with their PIN. Wrong PINs count toward the same lock as
     * login and PIN change; the counter is saved even though the request fails.
     */
    public function approve(WithdrawalAuthorization $authorization, Customer $customer, string $pin): WithdrawalAuthorization
    {
        $authorization = $this->current($authorization);
        $this->ensureStatus($authorization, Status::PENDING);

        if ($customer->account_status !== AccountStatus::ACTIVE) {
            throw new AccountNotActiveException($customer->account_status);
        }

        // Outside the transaction below, so a failed attempt is not rolled back.
        $this->pins->verify($customer, $pin);

        return DB::transaction(function () use ($authorization) {
            $updated = WithdrawalAuthorization::whereKey($authorization->id)
                ->where('status', Status::PENDING)
                ->where('expires_at', '>', now())
                ->update([
                    'status' => Status::APPROVED,
                    'approved_at' => now(),
                    'approval_expires_at' => now()->addSeconds(config('bank.withdrawal_authorization_approved_seconds')),
                    'updated_at' => now(),
                ]);

            $authorization = $this->current($authorization);

            if ($updated === 0) {
                throw AuthorizationNotUsableException::status($authorization);
            }

            return $authorization;
        });
    }

    public function reject(WithdrawalAuthorization $authorization): WithdrawalAuthorization
    {
        return $this->transition($authorization, [Status::PENDING], [
            'status' => Status::REJECTED,
            'rejected_at' => now(),
        ]);
    }

    public function cancel(WithdrawalAuthorization $authorization): WithdrawalAuthorization
    {
        return $this->transition($authorization, Status::open(), [
            'status' => Status::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => 'teller',
        ]);
    }

    /**
     * Fresh copy of the authorization with overdue expiry applied, so every
     * reader sees the real status even before the scheduled sweep runs.
     */
    public function current(WithdrawalAuthorization $authorization): WithdrawalAuthorization
    {
        $authorization = $authorization->fresh();

        if ($authorization->status->isOpen() && $authorization->isPastDeadline()) {
            $this->expireOverdue(WithdrawalAuthorization::whereKey($authorization->id));
            $authorization = $authorization->fresh();
        }

        return $authorization;
    }

    /**
     * Mark every overdue pending/approved authorization in the query as expired.
     * Used lazily and by the scheduled sweep (bank:expire-withdrawal-authorizations).
     */
    public function expireOverdue($query = null): int
    {
        $query ??= WithdrawalAuthorization::query();

        $pending = (clone $query)->where('status', Status::PENDING)->where('expires_at', '<=', now())
            ->update(['status' => Status::EXPIRED, 'updated_at' => now()]);
        $approved = (clone $query)->where('status', Status::APPROVED)->where('approval_expires_at', '<=', now())
            ->update(['status' => Status::EXPIRED, 'updated_at' => now()]);

        return $pending + $approved;
    }

    /**
     * Check the balance rule the withdrawal will have to pass, so the customer is
     * not asked to approve something that would fail anyway.
     */
    private function ensureBalanceAllows(Customer $customer, int $amount): void
    {
        $minBalance = $this->settings->minBalance();

        if ($customer->balance - $amount < $minBalance) {
            throw new InsufficientBalanceException($minBalance, max($customer->balance - $minBalance, 0));
        }
    }

    private function ensureStatus(WithdrawalAuthorization $authorization, Status ...$allowed): void
    {
        if (! in_array($authorization->status, $allowed, true)) {
            throw AuthorizationNotUsableException::status($authorization);
        }
    }

    /**
     * Compare-and-set status change: only applies if the authorization is still
     * in one of the allowed statuses and not overdue.
     *
     * @param  array<int, Status>  $from
     */
    private function transition(WithdrawalAuthorization $authorization, array $from, array $changes): WithdrawalAuthorization
    {
        $authorization = $this->current($authorization);
        $this->ensureStatus($authorization, ...$from);

        $updated = WithdrawalAuthorization::whereKey($authorization->id)
            ->where('status', $authorization->status)
            ->update([...$changes, 'updated_at' => now()]);

        $authorization = $this->current($authorization);

        if ($updated === 0) {
            throw AuthorizationNotUsableException::status($authorization);
        }

        return $authorization;
    }
}
