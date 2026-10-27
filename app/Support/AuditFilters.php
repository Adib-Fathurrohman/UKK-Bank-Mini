<?php

namespace App\Support;

use App\Enums\TransactionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filters for the audit screens and exports (interview: date range first,
 * then teller name, customer name and account number).
 */
final class AuditFilters
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            // "2026-10-23" covers the whole day; "2026-10-23 07:30" is exact.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::enum(TransactionTypes::class)],
            'teller_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            // Free text: teller name or username.
            'teller' => ['nullable', 'string', 'max:100'],
            // Free text: customer name, account number or NIS.
            'customer' => ['nullable', 'string', 'max:100'],
        ];
    }

    public static function dateRange(Builder $query, Request $request, string $column): Builder
    {
        if ($from = $request->input('from')) {
            $query->where($column, '>=', self::isDateOnly($from) ? Carbon::parse($from)->startOfDay() : Carbon::parse($from));
        }

        if ($to = $request->input('to')) {
            $query->where($column, '<=', self::isDateOnly($to) ? Carbon::parse($to)->endOfDay() : Carbon::parse($to)->endOfMinute());
        }

        return $query;
    }

    /**
     * Apply the transaction-level filters (everything except the date range).
     */
    public static function transactions(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('teller_id'), fn ($query, $id) => $query->where('user_id', $id))
            ->when($request->input('customer_id'), fn ($query, $id) => $query->where('customer_id', $id))
            ->when($request->input('teller'), fn ($query, $search) => $query->whereHas('teller', fn ($query) => $query
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")))
            ->when($request->input('customer'), fn ($query, $search) => $query->whereHas('customer', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('nis', 'like', "%{$search}%"))));
    }

    public static function hasTransactionFilters(Request $request): bool
    {
        return $request->hasAny(['type', 'teller_id', 'customer_id', 'teller', 'customer'])
            && collect($request->only(['type', 'teller_id', 'customer_id', 'teller', 'customer']))->filter()->isNotEmpty();
    }

    private static function isDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
