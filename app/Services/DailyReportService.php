<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\TransactionTypes;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The teller's end-of-day cash closing report (FR-Trx-03) and its supervisor
 * approval (FR-Spv-01). Totals are always computed from the transactions
 * themselves, never taken from the client.
 */
class DailyReportService
{
    /**
     * @return array{transaction_count: int, total_deposit: int, total_withdrawal: int}
     */
    public function summarize(User $teller, CarbonInterface $date): array
    {
        $totals = $teller->transactions()
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->selectRaw('type, COUNT(*) as aggregate_count, SUM(amount) as aggregate_total')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        return [
            'transaction_count' => (int) $totals->sum('aggregate_count'),
            'total_deposit' => (int) $totals->get(TransactionTypes::DEPOSIT->value)?->aggregate_total,
            'total_withdrawal' => (int) $totals->get(TransactionTypes::WITHDRAWAL->value)?->aggregate_total,
        ];
    }

    public function findFor(User $teller, CarbonInterface $date): ?DailyReport
    {
        return DailyReport::where('teller_id', $teller->id)->whereDate('report_date', $date)->first();
    }

    /**
     * Once a day's report is submitted (or approved) the teller's counter is
     * closed for that day, so the reported totals cannot drift afterwards.
     */
    public function isCounterClosed(User $teller, CarbonInterface $date): bool
    {
        return DailyReport::where('teller_id', $teller->id)
            ->whereDate('report_date', $date)
            ->whereIn('status', [ReportStatus::SUBMITTED, ReportStatus::APPROVED])
            ->exists();
    }

    /**
     * Submit (or resubmit after rejection) the report for one day.
     */
    public function submit(User $teller, CarbonInterface $date): DailyReport
    {
        return DB::transaction(function () use ($teller, $date) {
            // Same lock the TransactionService takes, so no transaction can slip
            // in between computing the totals and closing the counter.
            User::whereKey($teller->id)->lockForUpdate()->first();

            $report = $this->findFor($teller, $date) ?? new DailyReport([
                'teller_id' => $teller->id,
                'report_date' => $date->toDateString(),
            ]);

            if (in_array($report->status, [ReportStatus::SUBMITTED, ReportStatus::APPROVED], true)) {
                throw new InvalidStatusTransitionException("Laporan tanggal {$date->toDateString()} sudah {$report->status->value}.");
            }

            $report->fill([
                ...$this->summarize($teller, $date),
                'status' => ReportStatus::SUBMITTED,
                'submitted_at' => now(),
                'supervisor_id' => null,
                'reviewed_at' => null,
                'supervisor_note' => null,
            ])->save();

            return $report;
        });
    }

    public function approve(DailyReport $report, User $supervisor, ?string $note = null): DailyReport
    {
        return $this->review($report, $supervisor, ReportStatus::APPROVED, $note);
    }

    /**
     * Rejecting reopens the teller's counter so they can correct and resubmit.
     */
    public function reject(DailyReport $report, User $supervisor, string $note): DailyReport
    {
        return $this->review($report, $supervisor, ReportStatus::REJECTED, $note);
    }

    private function review(DailyReport $report, User $supervisor, ReportStatus $status, ?string $note): DailyReport
    {
        return DB::transaction(function () use ($report, $supervisor, $status, $note) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($report->status !== ReportStatus::SUBMITTED) {
                throw new InvalidStatusTransitionException("Laporan berstatus {$report->status->value} tidak dapat diproses lagi.");
            }

            $report->update([
                'status' => $status,
                'supervisor_id' => $supervisor->id,
                'reviewed_at' => now(),
                'supervisor_note' => $note,
            ]);

            return $report;
        });
    }
}
