<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\TransactionTypes;
use App\Exceptions\AccountNotActiveException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cash deposits and withdrawals executed by a teller.
 *
 * Balance update, transaction row and both journal lines are written in one
 * database transaction (NFR-DB-03): if any step fails, nothing is saved.
 */
class TransactionService
{
    public function __construct(private JournalService $journals)
    {
    }

    public function deposit(Customer $customer, int $amount, User $teller): Transaction
    {
        return $this->record(TransactionTypes::DEPOSIT, $customer, $amount, $teller);
    }

    public function withdraw(Customer $customer, int $amount, User $teller): Transaction
    {
        return $this->record(TransactionTypes::WITHDRAWAL, $customer, $amount, $teller);
    }

    private function record(TransactionTypes $type, Customer $customer, int $amount, User $teller): Transaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal transaksi harus lebih dari nol.');
        }

        return DB::transaction(function () use ($type, $customer, $amount, $teller) {
            // Re-read the balance under a row lock so two tellers serving the same
            // customer at once cannot both start from the same old balance.
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($customer->account_status !== AccountStatus::ACTIVE) {
                throw new AccountNotActiveException($customer->account_status);
            }

            $balanceBefore = $customer->balance;
            $balanceAfter = $type === TransactionTypes::DEPOSIT
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            $minBalance = config('bank.min_balance');

            if ($type === TransactionTypes::WITHDRAWAL && $balanceAfter < $minBalance) {
                throw new InsufficientBalanceException($minBalance, max($balanceBefore - $minBalance, 0));
            }

            $customer->update(['balance' => $balanceAfter]);

            $transaction = $customer->transactions()->create([
                'user_id' => $teller->id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            $this->journals->record($transaction);

            return $transaction->setRelation('customer', $customer);
        });
    }
}
