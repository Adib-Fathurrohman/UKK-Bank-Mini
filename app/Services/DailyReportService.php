<?php

namespace App\Services;

use App\Enums\CashCountStage;
use App\Enums\ReportStatus;
use App\Enums\TransactionTypes;
use App\Enums\WithdrawalAuthorizationStatus;
use App\Exceptions\CashMismatchException;
use App\Exceptions\CorrectionNotAllowedException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\ShiftNotOpenException;
use App\Models\CashCount;
use App\Models\CashTransfer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * The teller's shift and its cash closing report (soal 2c, rubric 2.4).
 *
 * 1. Open:  count the cash in the drawer (saldo awal).
 * 2. Work:  every deposit/withdrawal is recorded in the open shift.
 * 3. Close: count the cash again (uang fisik). It must equal
 *           saldo awal + total setoran - total penarikan, otherwise the
 *           report stays locked.
 * 4. Review: the supervisor approves (cash moves to the vault), puts the
 *           report on hold, or rejects it (the shift reopens for correction).
 *
 * Totals always come from the transactions, never from the client.
 */
class DailyReportService
{
    /**
     * The shift the teller is still working in (open or rejected), if any.
     * There is never more than one.
     */
    public function activeShift(User $teller, bool $lock = false): ?DailyReport
    {
        return DailyReport::where('teller_id', $teller->id)
            ->whereIn('status', [ReportStatus::OPEN, ReportStatus::REJECTED])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * The shift a new deposit or withdrawal belongs to. Must be called inside a
     * database transaction: it locks the teller and the shift so that closing
     * cannot happen halfway through a transaction.
     */
    public function shiftForTransaction(User $teller): DailyReport
    {
        User::whereKey($teller->id)->lockForUpdate()->first();

        $shift = $this->activeShift($teller, lock: true);

        if (! $shift) {
            $closedToday = DailyReport::where('teller_id', $teller->id)->whereDate('report_date', today())->exists();

            throw $closedToday ? ShiftNotOpenException::alreadyClosedToday() : ShiftNotOpenException::none();
        }

        if (! $shift->report_date->isToday()) {
            throw ShiftNotOpenException::fromPastDay($shift->report_date->toDateString());
        }

        return $shift;
    }

    /**
     * The shift a correction belongs to: the same shift as the transaction it
     * reverses, while that shift is still active (open, or reopened by a
     * rejection). Locks like shiftForTransaction().
     */
    public function shiftForCorrection(User $teller, Transaction $original): DailyReport
    {
        User::whereKey($teller->id)->lockForUpdate()->first();

        $shift = $this->activeShift($teller, lock: true);

        if (! $shift || $shift->id !== $original->daily_report_id) {
            throw new CorrectionNotAllowedException('Koreksi hanya dapat dilakukan pada shift tempat transaksi asli dicatat, selama shift tersebut belum ditutup.');
        }

        return $shift;
    }

    /**
     * Start today's shift with the counted opening cash.
     *
     * @param  array<int|string, int>  $denominations  denomination => quantity
     */
    public function open(User $teller, array $denominations): DailyReport
    {
        $counts = $this->normalize($denominations);

        return DB::transaction(function () use ($teller, $counts) {
            User::whereKey($teller->id)->lockForUpdate()->first();

            if ($active = $this->activeShift($teller)) {
                throw new InvalidStatusTransitionException("Shift tanggal {$active->report_date->toDateString()} masih berjalan. Tutup shift tersebut terlebih dahulu.");
            }

            if (DailyReport::where('teller_id', $teller->id)->whereDate('report_date', today())->exists()) {
                throw new InvalidStatusTransitionException('Shift hari ini sudah ditutup dan tidak dapat dibuka lagi.');
            }

            $shift = DailyReport::create([
                'teller_id' => $teller->id,
                'report_date' => today()->toDateString(),
                'status' => ReportStatus::OPEN,
                'opening_balance' => $this->total($counts),
                'opened_at' => now(),
            ]);

            $this->saveCounts($shift, CashCountStage::OPENING, $counts);

            return $shift;
        });
    }

    /**
     * Close the active shift. Nothing is saved when the physical cash does not
     * match the system balance.
     *
     * @param  array<int|string, int>  $denominations  denomination => quantity
     */
    public function close(User $teller, array $denominations): DailyReport
    {
        $counts = $this->normalize($denominations);

        return DB::transaction(function () use ($teller, $counts) {
            User::whereKey($teller->id)->lockForUpdate()->first();

            $shift = $this->activeShift($teller, lock: true) ?? throw ShiftNotOpenException::none();

            $summary = $this->summarize($shift);
            $physical = $this->total($counts);

            if ($physical !== $summary['system_balance']) {
                throw new CashMismatchException($summary['system_balance'], $physical);
            }

            $shift->fill([
                ...$summary,
                'physical_balance' => $physical,
                'status' => ReportStatus::SUBMITTED,
                'submitted_at' => now(),
                'supervisor_id' => null,
                'reviewed_at' => null,
                'supervisor_note' => null,
            ])->save();

            // A shift closed again after a rejection replaces its earlier count.
            CashCount::where('daily_report_id', $shift->id)->where('stage', CashCountStage::CLOSING)->delete();
            $this->saveCounts($shift, CashCountStage::CLOSING, $counts);

            // Withdrawal requests still waiting in this shift can no longer be completed.
            WithdrawalAuthorization::where('daily_report_id', $shift->id)
                ->whereIn('status', WithdrawalAuthorizationStatus::open())
                ->update([
                    'status' => WithdrawalAuthorizationStatus::CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'shift_closed',
                    'updated_at' => now(),
                ]);

            return $shift;
        });
    }

    /**
     * Live totals of a shift computed from its transactions.
     *
     * @return array{transaction_count: int, total_deposit: int, total_withdrawal: int, system_balance: int}
     */
    public function summarize(DailyReport $shift): array
    {
        $totals = $shift->transactions()
            ->selectRaw('type, COUNT(*) as aggregate_count, SUM(amount) as aggregate_total')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        $sum = fn (TransactionTypes $type) => (int) $totals->get($type->value)?->aggregate_total;

        // Totals are net of corrections: a reversed deposit was never really received.
        $deposit = $sum(TransactionTypes::DEPOSIT) - $sum(TransactionTypes::DEPOSIT_CORRECTION);
        $withdrawal = $sum(TransactionTypes::WITHDRAWAL) - $sum(TransactionTypes::WITHDRAWAL_CORRECTION);

        return [
            'transaction_count' => (int) $totals->sum('aggregate_count'),
            'total_deposit' => $deposit,
            'total_withdrawal' => $withdrawal,
            'system_balance' => $shift->opening_balance + $deposit - $withdrawal,
        ];
    }

    /**
     * Approve a closed shift. The counted cash is booked from the teller's
     * drawer to the main vault in the same step (soal 1c).
     */
    public function approve(DailyReport $report, User $supervisor, ?string $note = null): DailyReport
    {
        return DB::transaction(function () use ($report, $supervisor, $note) {
            $report = $this->lockForReview($report);

            $report->update($this->reviewAttributes(ReportStatus::APPROVED, $supervisor, $note));

            if ($report->physical_balance > 0) {
                CashTransfer::create([
                    'daily_report_id' => $report->id,
                    'amount' => $report->physical_balance,
                    'approved_by' => $supervisor->id,
                    'transferred_at' => now(),
                ]);
            }

            return $report;
        });
    }

    /**
     * Keep a report pending (e.g. an unresolved question) without approving
     * or reopening it. The teller can still open tomorrow's shift.
     */
    public function hold(DailyReport $report, User $supervisor, string $note): DailyReport
    {
        return DB::transaction(function () use ($report, $supervisor, $note) {
            $report = $this->lockForReview($report);

            if ($report->status === ReportStatus::ON_HOLD) {
                throw new InvalidStatusTransitionException('Laporan ini sudah ditahan.');
            }

            $report->update($this->reviewAttributes(ReportStatus::ON_HOLD, $supervisor, $note));

            return $report;
        });
    }

    /**
     * Send the report back: the shift reopens so the teller can correct it and close again.
     */
    public function reject(DailyReport $report, User $supervisor, string $note): DailyReport
    {
        return DB::transaction(function () use ($report, $supervisor, $note) {
            $report = $this->lockForReview($report);

            if ($this->activeShift($report->teller) !== null) {
                throw new InvalidStatusTransitionException('Teller sedang menjalankan shift lain. Tahan laporan ini sampai shift tersebut ditutup.');
            }

            $report->update($this->reviewAttributes(ReportStatus::REJECTED, $supervisor, $note));

            return $report;
        });
    }

    /**
     * Fill an open shift with its live totals (without saving) so it can be shown.
     */
    public function withLiveTotals(DailyReport $shift): DailyReport
    {
        if ($shift->status->isActive()) {
            $shift->fill($this->summarize($shift));
        }

        return $shift;
    }

    private function lockForReview(DailyReport $report): DailyReport
    {
        $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

        if (! $report->status->isAwaitingReview()) {
            throw new InvalidStatusTransitionException('Laporan berstatus "'.strtolower($report->status->label()).'" tidak dapat diproses.');
        }

        return $report;
    }

    private function reviewAttributes(ReportStatus $status, User $supervisor, ?string $note): array
    {
        return [
            'status' => $status,
            'supervisor_id' => $supervisor->id,
            'reviewed_at' => now(),
            'supervisor_note' => $note,
        ];
    }

    /**
     * @param  array<int|string, int>  $denominations
     * @return array<int, int> denomination => quantity, zero quantities removed
     */
    private function normalize(array $denominations): array
    {
        $counts = [];
        foreach ($denominations as $denomination => $quantity) {
            if ((int) $quantity > 0) {
                $counts[(int) $denomination] = (int) $quantity;
            }
        }
        krsort($counts);

        return $counts;
    }

    /**
     * @param  array<int, int>  $counts
     */
    private function total(array $counts): int
    {
        return array_sum(array_map(fn ($denomination, $quantity) => $denomination * $quantity, array_keys($counts), $counts));
    }

    /**
     * @param  array<int, int>  $counts
     */
    private function saveCounts(DailyReport $shift, CashCountStage $stage, array $counts): void
    {
        foreach ($counts as $denomination => $quantity) {
            $shift->cashCounts()->create([
                'stage' => $stage,
                'denomination' => $denomination,
                'quantity' => $quantity,
            ]);
        }
    }
}
