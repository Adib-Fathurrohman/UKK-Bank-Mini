<?php

namespace App\Services;

use App\Enums\AccountCode;
use App\Enums\TransactionTypes;
use App\Models\Customer;
use App\Models\Transaction;
use App\Support\SchoolYear;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data behind the printable reports. Every figure comes from the transactions,
 * whose balance_before/balance_after form an unbroken chain per customer.
 */
class ReportService
{
    /**
     * A customer's statement (kartu simpanan / mutasi rekening) for a period.
     *
     * @return array{customer: Customer, from: ?CarbonInterface, to: ?CarbonInterface, opening_balance: int, lines: Collection<int, Transaction>, total_in: int, total_out: int, closing_balance: int}
     */
    public function statement(Customer $customer, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $opening = $from
            ? (int) $customer->transactions()->where('created_at', '<', $from)->latest('id')->value('balance_after')
            : 0;

        $lines = $customer->transactions()
            ->with('teller')
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->oldest('id')
            ->get();

        $totalIn = (int) $lines->filter(fn (Transaction $line) => $line->type->increasesBalance())->sum('amount');
        $totalOut = (int) $lines->reject(fn (Transaction $line) => $line->type->increasesBalance())->sum('amount');

        return [
            'customer' => $customer->loadMissing('major'),
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'lines' => $lines,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'closing_balance' => $lines->last()?->balance_after ?? $opening,
        ];
    }

    /**
     * Annual report for one school year (July-June): opening balance, deposits,
     * withdrawals (both net of corrections) and closing balance per account,
     * plus the totals per savings product.
     *
     * @return array{label: string, from: CarbonInterface, to: CarbonInterface, rows: Collection<int, array<string, mixed>>, products: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function annual(int $schoolYear): array
    {
        [$from, $to] = SchoolYear::range($schoolYear);

        // Balance of every account just before the school year started.
        $openingBalances = Transaction::query()
            ->whereIn('id', Transaction::query()
                ->where('created_at', '<', $from)
                ->groupBy('customer_id')
                ->select(DB::raw('MAX(id)')))
            ->pluck('balance_after', 'customer_id');

        $movements = Transaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('customer_id, type, SUM(amount) as aggregate_total')
            ->groupBy('customer_id', 'type')
            ->get()
            ->groupBy('customer_id');

        $customerIds = $openingBalances->keys()->merge($movements->keys())->unique();

        $rows = Customer::withTrashed()
            ->with('major')
            ->whereIn('id', $customerIds)
            ->orderBy('account_number')
            ->get()
            ->map(function (Customer $customer) use ($openingBalances, $movements) {
                $sum = fn (TransactionTypes $type) => (int) $movements->get($customer->id)
                    ?->first(fn ($row) => $row->type === $type)
                    ?->aggregate_total;

                $opening = (int) ($openingBalances[$customer->id] ?? 0);
                $deposits = $sum(TransactionTypes::DEPOSIT) - $sum(TransactionTypes::DEPOSIT_CORRECTION);
                $withdrawals = $sum(TransactionTypes::WITHDRAWAL) - $sum(TransactionTypes::WITHDRAWAL_CORRECTION);

                return [
                    'customer' => $customer,
                    'opening_balance' => $opening,
                    'deposits' => $deposits,
                    'withdrawals' => $withdrawals,
                    'closing_balance' => $opening + $deposits - $withdrawals,
                ];
            })
            // Accounts closed before the year with nothing left are not part of it.
            ->reject(fn (array $row) => $row['customer']->trashed()
                && $row['opening_balance'] === 0 && $row['deposits'] === 0 && $row['withdrawals'] === 0)
            ->values();

        $totals = [
            'opening_balance' => $rows->sum('opening_balance'),
            'deposits' => $rows->sum('deposits'),
            'withdrawals' => $rows->sum('withdrawals'),
            'closing_balance' => $rows->sum('closing_balance'),
        ];

        return [
            'label' => SchoolYear::label($schoolYear),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            // Every account is a student savings account (201 Tabungan Nasabah).
            'products' => [[
                'account_code' => AccountCode::CUSTOMER_SAVINGS->value,
                'name' => AccountCode::CUSTOMER_SAVINGS->label(),
                'accounts' => $rows->count(),
                ...$totals,
            ]],
            'totals' => $totals,
        ];
    }
}
