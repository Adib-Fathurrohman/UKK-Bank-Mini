<?php

namespace App\Services;

use App\Enums\CashTransferStatus;
use App\Enums\TransactionTypes;
use App\Exceptions\InsufficientDrawerCashException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\CashTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moving physical cash from a teller's drawer to the main vault (FR-Spv-02).
 *
 * The teller requests a transfer, a supervisor authorizes or rejects it.
 * Drawer cash is derived from the teller's own transactions: every deposit
 * puts cash in, every withdrawal takes it out, every approved transfer moves
 * it to the vault.
 */
class CashTransferService
{
    /**
     * @return array{cash_in: int, cash_out: int, transferred: int, drawer_balance: int, pending: int, available_for_transfer: int}
     */
    public function drawer(User $teller): array
    {
        $totals = $teller->transactions()
            ->selectRaw('type, SUM(amount) as aggregate_total')
            ->groupBy('type')
            ->pluck('aggregate_total', 'type');

        $transfers = $teller->cashTransfers()
            ->whereIn('status', [CashTransferStatus::APPROVED, CashTransferStatus::PENDING])
            ->selectRaw('status, SUM(amount) as aggregate_total')
            ->groupBy('status')
            ->pluck('aggregate_total', 'status');

        $cashIn = (int) ($totals[TransactionTypes::DEPOSIT->value] ?? 0);
        $cashOut = (int) ($totals[TransactionTypes::WITHDRAWAL->value] ?? 0);
        $transferred = (int) ($transfers[CashTransferStatus::APPROVED->value] ?? 0);
        $pending = (int) ($transfers[CashTransferStatus::PENDING->value] ?? 0);
        $drawerBalance = $cashIn - $cashOut - $transferred;

        return [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'transferred' => $transferred,
            'drawer_balance' => $drawerBalance,
            'pending' => $pending,
            'available_for_transfer' => max($drawerBalance - $pending, 0),
        ];
    }

    public function request(User $teller, int $amount, ?string $note = null): CashTransfer
    {
        return DB::transaction(function () use ($teller, $amount, $note) {
            // Serializes with the teller's own transactions and other requests.
            User::whereKey($teller->id)->lockForUpdate()->first();

            $available = $this->drawer($teller)['available_for_transfer'];

            if ($amount > $available) {
                throw new InsufficientDrawerCashException($available);
            }

            return $teller->cashTransfers()->create([
                'amount' => $amount,
                'note' => $note,
                'status' => CashTransferStatus::PENDING,
            ]);
        });
    }

    public function approve(CashTransfer $transfer, User $supervisor, ?string $note = null): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $supervisor, $note) {
            User::whereKey($transfer->teller_id)->lockForUpdate()->first();
            $transfer = $this->lockPending($transfer);

            // Withdrawals since the request may have taken cash out of the drawer.
            $drawerBalance = $this->drawer($transfer->teller)['drawer_balance'];

            if ($transfer->amount > $drawerBalance) {
                throw new InsufficientDrawerCashException($drawerBalance);
            }

            return $this->review($transfer, $supervisor, CashTransferStatus::APPROVED, $note);
        });
    }

    public function reject(CashTransfer $transfer, User $supervisor, string $note): CashTransfer
    {
        return DB::transaction(fn () => $this->review($this->lockPending($transfer), $supervisor, CashTransferStatus::REJECTED, $note));
    }

    private function lockPending(CashTransfer $transfer): CashTransfer
    {
        $transfer = CashTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if ($transfer->status !== CashTransferStatus::PENDING) {
            throw new InvalidStatusTransitionException("Pemindahan kas berstatus {$transfer->status->value} tidak dapat diproses lagi.");
        }

        return $transfer;
    }

    private function review(CashTransfer $transfer, User $supervisor, CashTransferStatus $status, ?string $note): CashTransfer
    {
        $transfer->update([
            'status' => $status,
            'supervisor_id' => $supervisor->id,
            'reviewed_at' => now(),
            'supervisor_note' => $note,
        ]);

        return $transfer;
    }
}
