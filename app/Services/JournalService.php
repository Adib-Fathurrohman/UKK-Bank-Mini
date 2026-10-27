<?php

namespace App\Services;

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Enums\TransactionTypes;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes the double-entry journal for a teller transaction (FR-Acc-01/02).
 *
 * Deposit:    Debit 101 Kas,               Credit 201 Tabungan Nasabah
 * Withdrawal: Debit 201 Tabungan Nasabah,  Credit 101 Kas
 *
 * A correction reverses the original, so it gets the opposite pair: a deposit
 * correction is booked like a withdrawal and vice versa.
 */
class JournalService
{
    /**
     * The two opposite journal lines for a transaction type.
     *
     * @return array<int, array{account_code: AccountCode, position: Position}>
     */
    public static function entriesFor(TransactionTypes $type): array
    {
        return $type->increasesBalance()
            ? [
                ['account_code' => AccountCode::CASH, 'position' => Position::DEBIT],
                ['account_code' => AccountCode::CUSTOMER_SAVINGS, 'position' => Position::CREDIT],
            ]
            : [
                ['account_code' => AccountCode::CUSTOMER_SAVINGS, 'position' => Position::DEBIT],
                ['account_code' => AccountCode::CASH, 'position' => Position::CREDIT],
            ];
    }

    public function record(Transaction $transaction): Collection
    {
        return $transaction->accountingJournals()->createMany(array_map(
            fn (array $entry) => [...$entry, 'amount' => $transaction->amount],
            self::entriesFor($transaction->type),
        ));
    }
}
