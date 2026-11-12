<?php

namespace App\Services;

use App\Enums\CashCountStage;
use App\Enums\TransactionTypes;
use App\Exceptions\CashBreakdownException;
use App\Models\CashCount;
use App\Models\DailyReport;
use App\Models\TransactionCash;
use Illuminate\Support\Facades\DB;

/**
 * The notes and coins in a teller's drawer during a shift.
 *
 * Drawer = opening count + notes received - notes paid out, per denomination.
 * For a tracked shift every cash transaction records its notes, so:
 *
 *   - a deposit brings in notes that add up exactly to the amount (no change);
 *   - a withdrawal can only pay out notes the drawer holds;
 *   - closing must match the drawer per denomination (DailyReportService::close).
 *
 * When the teller does not say which notes moved, deposits are taken as the
 * fewest notes and payouts as the largest notes the drawer can combine into the
 * amount. The staff web app always sends what was actually counted.
 *
 * Callers hold the shift lock (DailyReportService::shiftForTransaction), so the
 * drawer cannot change between the check and the write.
 */
class CashDrawerService
{
    /** Upper bound of combinations tried when choosing notes for a payout. */
    private const SEARCH_LIMIT = 200000;

    /**
     * What the drawer holds now.
     *
     * @return array<int, int> denomination => quantity, largest first, empty denominations left out
     */
    public function contents(DailyReport $shift): array
    {
        $drawer = [];

        $opening = CashCount::where('daily_report_id', $shift->id)->where('stage', CashCountStage::OPENING)->get();
        foreach ($opening as $count) {
            $drawer[$count->denomination] = ($drawer[$count->denomination] ?? 0) + $count->quantity;
        }

        $in = array_map(fn (TransactionTypes $type) => $type->value, array_filter(TransactionTypes::cases(), fn ($type) => $type->addsCash()));

        $moved = TransactionCash::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_cash.transaction_id')
            ->where('transactions.daily_report_id', $shift->id)
            ->groupBy('transaction_cash.denomination')
            ->select('transaction_cash.denomination')
            ->selectRaw('SUM(CASE WHEN transactions.type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN transaction_cash.quantity ELSE -transaction_cash.quantity END) AS net', $in)
            ->get();

        foreach ($moved as $row) {
            $drawer[(int) $row->denomination] = ($drawer[(int) $row->denomination] ?? 0) + (int) $row->net;
        }

        return $this->clean($drawer);
    }

    /**
     * The notes a transaction moves, checked against the amount and the drawer.
     * Returns [] for a shift opened before per-note tracking.
     *
     * @param  array<int|string, int>|null  $given  denomination => quantity, or null to let the system choose
     * @return array<int, int>
     */
    public function notesFor(DailyReport $shift, TransactionTypes $type, int $amount, ?array $given): array
    {
        if (! $shift->cash_tracked) {
            return [];
        }

        if ($type->addsCash()) {
            $notes = $given === null ? $this->fewestNotes($amount) : $this->clean($given);
            $this->ensureTotal($notes, $amount);

            return $notes;
        }

        $drawer = $this->contents($shift);

        if ($given === null) {
            return $this->payout($amount, $drawer) ?? throw CashBreakdownException::cannotPay($amount);
        }

        $notes = $this->clean($given);
        $this->ensureTotal($notes, $amount);

        $shortages = [];
        foreach ($notes as $denomination => $quantity) {
            if ($quantity > ($drawer[$denomination] ?? 0)) {
                $shortages[$denomination] = ['requested' => $quantity, 'available' => $drawer[$denomination] ?? 0];
            }
        }

        if ($shortages) {
            throw CashBreakdownException::notInDrawer($shortages);
        }

        return $notes;
    }

    /**
     * @param  array<int, int>  $notes
     */
    public function record(int $transactionId, array $notes): void
    {
        if (! $notes) {
            return;
        }

        DB::table('transaction_cash')->insert(array_map(fn ($denomination, $quantity) => [
            'transaction_id' => $transactionId,
            'denomination' => $denomination,
            'quantity' => $quantity,
            'created_at' => now(),
        ], array_keys($notes), $notes));
    }

    /**
     * Per-denomination difference between a count and the drawer (count - drawer), zeros left out.
     *
     * @param  array<int, int>  $counted
     * @param  array<int, int>  $drawer
     * @return array<int, array{expected: int, counted: int, difference: int}>
     */
    public function differences(array $counted, array $drawer): array
    {
        $differences = [];

        foreach (config('bank.denominations') as $denomination) {
            $expected = $drawer[$denomination] ?? 0;
            $actual = $counted[$denomination] ?? 0;

            if ($expected !== $actual) {
                $differences[$denomination] = ['expected' => $expected, 'counted' => $actual, 'difference' => $actual - $expected];
            }
        }

        return $differences;
    }

    /**
     * @param  array<int, int>  $notes
     */
    public function total(array $notes): int
    {
        return array_sum(array_map(fn ($denomination, $quantity) => $denomination * $quantity, array_keys($notes), $notes));
    }

    /**
     * @return array<int, int>
     */
    private function fewestNotes(int $amount): array
    {
        $notes = [];
        $remaining = $amount;

        foreach (config('bank.denominations') as $denomination) {
            if ($remaining >= $denomination) {
                $notes[$denomination] = intdiv($remaining, $denomination);
                $remaining %= $denomination;
            }
        }

        if ($remaining !== 0) {
            throw new CashBreakdownException(
                'Nominal tidak dapat dibayar dengan pecahan rupiah yang berlaku (kelipatan Rp'.min(config('bank.denominations')).').',
                'cash_breakdown_mismatch',
            );
        }

        return $notes;
    }

    /**
     * Notes from the drawer that add up to the amount, largest notes first, or
     * null when no combination exists (bounded search; amounts are small sets of
     * denominations, so the bound is never reached in practice).
     *
     * @param  array<int, int>  $drawer
     * @return array<int, int>|null
     */
    private function payout(int $amount, array $drawer): ?array
    {
        $denominations = array_keys($drawer);
        rsort($denominations);

        // Value of everything from position i onwards, to stop early.
        $rest = [];
        $sum = 0;
        for ($i = count($denominations) - 1; $i >= 0; $i--) {
            $sum += $denominations[$i] * $drawer[$denominations[$i]];
            $rest[$i] = $sum;
        }

        $budget = self::SEARCH_LIMIT;

        $search = function (int $i, int $remaining) use (&$search, &$budget, $denominations, $drawer, $rest): ?array {
            if ($remaining === 0) {
                return [];
            }
            if ($i >= count($denominations) || $rest[$i] < $remaining) {
                return null;
            }

            $denomination = $denominations[$i];
            for ($take = min($drawer[$denomination], intdiv($remaining, $denomination)); $take >= 0; $take--) {
                if (--$budget < 0) {
                    return null;
                }
                $found = $search($i + 1, $remaining - $take * $denomination);
                if ($found !== null) {
                    return $take > 0 ? [$denomination => $take] + $found : $found;
                }
            }

            return null;
        };

        $notes = $search(0, $amount);

        return $notes === null ? null : $this->clean($notes);
    }

    /**
     * @param  array<int, int>  $notes
     */
    private function ensureTotal(array $notes, int $amount): void
    {
        if (($total = $this->total($notes)) !== $amount) {
            throw CashBreakdownException::totalMismatch($amount, $total);
        }
    }

    /**
     * @param  array<int|string, int>  $notes
     * @return array<int, int> integer keys, positive quantities, largest first
     */
    private function clean(array $notes): array
    {
        $clean = [];
        foreach ($notes as $denomination => $quantity) {
            if ((int) $quantity > 0) {
                $clean[(int) $denomination] = (int) $quantity;
            }
        }
        krsort($clean);

        return $clean;
    }
}
