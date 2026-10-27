<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\TransactionTypes;
use App\Exceptions\AccountNotActiveException;
use App\Exceptions\CorrectionNotAllowedException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cash deposits, withdrawals and their corrections, executed by a teller.
 *
 * Balance update, transaction row and both journal lines are written in one
 * database transaction (NFR-DB-03): if any step fails, nothing is saved.
 *
 * Every method accepts an optional idempotency key. Repeating a request with
 * the same key returns the transaction that was already recorded instead of
 * recording it twice (e.g. when the connection dropped before the response).
 */
class TransactionService
{
    public function __construct(
        private JournalService $journals,
        private DailyReportService $reports,
        private SettingsService $settings,
        private PinService $pins,
    ) {
    }

    public function deposit(Customer $customer, int $amount, User $teller, ?string $idempotencyKey = null): Transaction
    {
        return $this->replay($teller, $idempotencyKey, TransactionTypes::DEPOSIT, $customer, $amount)
            ?? $this->record(TransactionTypes::DEPOSIT, $customer, $amount, $teller, idempotencyKey: $idempotencyKey);
    }

    /**
     * The customer authorizes a withdrawal by typing their PIN at the counter (soal 1d).
     */
    public function withdraw(Customer $customer, int $amount, User $teller, string $pin, ?string $idempotencyKey = null): Transaction
    {
        if ($existing = $this->replay($teller, $idempotencyKey, TransactionTypes::WITHDRAWAL, $customer, $amount)) {
            return $existing;
        }

        $this->pins->verify($customer, $pin);

        return $this->record(TransactionTypes::WITHDRAWAL, $customer, $amount, $teller, idempotencyKey: $idempotencyKey);
    }

    /**
     * Reverse a transaction typed in error (interview: "transaksi koreksi").
     *
     * Saved transactions are never edited; instead a correction of the same
     * amount moves the money back, with its own opposite journal lines. Only the
     * teller's own transaction, in the shift that is still running, once, and
     * only with a supervisor's authorization.
     */
    public function correct(Transaction $original, User $teller, User $supervisor, string $reason, ?string $idempotencyKey = null): Transaction
    {
        $type = $original->type->correctionType()
            ?? throw new CorrectionNotAllowedException('Transaksi koreksi tidak dapat dikoreksi lagi.');

        return $this->replay($teller, $idempotencyKey, $type, $original->customer, $original->amount)
            ?? $this->record($type, $original->customer, $original->amount, $teller, $original, $supervisor, $reason, $idempotencyKey);
    }

    private function record(
        TransactionTypes $type,
        Customer $customer,
        int $amount,
        User $teller,
        ?Transaction $original = null,
        ?User $supervisor = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal transaksi harus lebih dari nol.');
        }

        try {
            return DB::transaction(function () use ($type, $customer, $amount, $teller, $original, $supervisor, $note, $idempotencyKey) {
                // Locks are always taken teller, shift, (original,) then customer, to avoid
                // deadlocks. The teller and shift locks keep the shift from closing halfway
                // through and serialize the teller's requests for the idempotency check.
                $shift = $original
                    ? $this->reports->shiftForCorrection($teller, $original)
                    : $this->reports->shiftForTransaction($teller);

                if ($existing = $this->replay($teller, $idempotencyKey, $type, $customer, $amount)) {
                    return $existing;
                }

                if ($original) {
                    $this->ensureCorrectable($original, $teller);
                }

                // Re-read the balance under a row lock so two tellers serving the same
                // customer at once cannot both start from the same old balance.
                $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

                $this->ensureAccountAllows($type, $customer);

                $balanceBefore = $customer->balance;
                $balanceAfter = $type->increasesBalance() ? $balanceBefore + $amount : $balanceBefore - $amount;

                $this->ensureBalanceAllows($type, $balanceBefore, $balanceAfter);

                $customer->update(['balance' => $balanceAfter]);

                $transaction = $customer->transactions()->create([
                    'user_id' => $teller->id,
                    'daily_report_id' => $shift->id,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'corrects_transaction_id' => $original?->id,
                    'authorized_by' => $supervisor?->id,
                    'note' => $note,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->journals->record($transaction);

                return $transaction->setRelation('customer', $customer);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced past the check: return the one that won.
            return $this->replay($teller, $idempotencyKey, $type, $customer, $amount) ?? throw $e;
        }
    }

    /**
     * The transaction already recorded under this idempotency key, if any.
     * A key reused for a different transaction is refused.
     */
    private function replay(User $teller, ?string $key, TransactionTypes $type, Customer $customer, int $amount): ?Transaction
    {
        if ($key === null) {
            return null;
        }

        $existing = Transaction::with('customer')->where('user_id', $teller->id)->where('idempotency_key', $key)->first();

        if ($existing && ($existing->type !== $type || $existing->customer_id !== $customer->id || $existing->amount !== $amount)) {
            throw new IdempotencyConflictException();
        }

        return $existing;
    }

    private function ensureCorrectable(Transaction $original, User $teller): void
    {
        $original = Transaction::whereKey($original->id)->lockForUpdate()->firstOrFail();

        if ($original->user_id !== $teller->id) {
            throw new CorrectionNotAllowedException('Teller hanya dapat mengoreksi transaksinya sendiri.');
        }

        if ($original->correction()->exists()) {
            throw new CorrectionNotAllowedException('Transaksi ini sudah pernah dikoreksi.');
        }
    }

    private function ensureAccountAllows(TransactionTypes $type, Customer $customer): void
    {
        // A correction undoes a mistake, so it is also allowed on a suspended account.
        $allowed = $type->isCorrection()
            ? [AccountStatus::ACTIVE, AccountStatus::SUSPENDED]
            : [AccountStatus::ACTIVE];

        if (! in_array($customer->account_status, $allowed, true)) {
            throw new AccountNotActiveException($customer->account_status);
        }
    }

    private function ensureBalanceAllows(TransactionTypes $type, int $balanceBefore, int $balanceAfter): void
    {
        if ($type === TransactionTypes::WITHDRAWAL) {
            $minBalance = $this->settings->minBalance();

            if ($balanceAfter < $minBalance) {
                throw new InsufficientBalanceException($minBalance, max($balanceBefore - $minBalance, 0));
            }
        }

        // Reversing a deposit may go below the minimum balance (the money was
        // never really deposited), but never below zero.
        if ($type === TransactionTypes::DEPOSIT_CORRECTION && $balanceAfter < 0) {
            throw new CorrectionNotAllowedException('Saldo nasabah sudah tidak mencukupi untuk membatalkan setoran ini.');
        }
    }
}
