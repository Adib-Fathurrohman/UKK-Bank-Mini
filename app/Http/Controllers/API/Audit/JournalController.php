<?php

namespace App\Http\Controllers\API\Audit;

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Http\Controllers\Controller;
use App\Http\Resources\JournalResource;
use App\Models\AccountingJournal;
use App\Support\AuditFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only accounting journal for administrators and supervisors (FR-Acc-03).
 * There are deliberately no write endpoints.
 */
class JournalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $journals = $this->filtered($request)
            ->with(['transaction.customer', 'transaction.teller'])
            ->latest('id')
            ->paginate($this->perPage($request, 30));

        return JournalResource::collection($journals);
    }

    /**
     * Debit and credit totals per account. Total debit must always equal total credit.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summarize($this->filtered($request))]);
    }

    /**
     * Laporan keuangan (jurnal akuntansi) as a printable PDF.
     */
    public function export(Request $request): Response
    {
        $journals = $this->filtered($request)
            ->with(['transaction.customer', 'transaction.teller'])
            ->oldest('id')
            ->limit(TransactionController::EXPORT_LIMIT + 1)
            ->get();

        return Pdf::loadView('pdf.journal', [
            'journals' => $journals->take(TransactionController::EXPORT_LIMIT),
            'truncated' => $journals->count() > TransactionController::EXPORT_LIMIT,
            'summary' => $this->summarize($this->filtered($request)),
            'filters' => $request->only(['from', 'to', 'teller', 'customer', 'account_code']),
        ])->setPaper('a4', 'landscape')->download('jurnal-akuntansi.pdf');
    }

    /**
     * @return array{accounts: \Illuminate\Support\Collection, total_debit: int, total_credit: int, is_balanced: bool}
     */
    private function summarize(Builder $query): array
    {
        $rows = $query
            ->selectRaw('account_code, position, SUM(amount) as total')
            ->groupBy('account_code', 'position')
            ->get();

        $accounts = collect(AccountCode::cases())->map(function (AccountCode $code) use ($rows) {
            $total = fn (Position $position) => (int) $rows
                ->first(fn ($row) => $row->account_code === $code && $row->position === $position)
                ?->total;

            return [
                'account_code' => $code,
                'account_name' => $code->label(),
                'total_debit' => $total(Position::DEBIT),
                'total_credit' => $total(Position::CREDIT),
            ];
        });

        $totalDebit = $accounts->sum('total_debit');
        $totalCredit = $accounts->sum('total_credit');

        return [
            'accounts' => $accounts,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            ...AuditFilters::rules(),
            'transaction_id' => ['nullable', 'integer'],
            'account_code' => ['nullable', Rule::enum(AccountCode::class)],
            'position' => ['nullable', Rule::enum(Position::class)],
        ]);

        return AuditFilters::dateRange(AccountingJournal::query(), $request, 'created_at')
            ->when($request->input('transaction_id'), fn ($query, $id) => $query->where('transaction_id', $id))
            ->when($request->input('account_code'), fn ($query, $code) => $query->where('account_code', $code))
            ->when($request->input('position'), fn ($query, $position) => $query->where('position', $position))
            ->when(AuditFilters::hasTransactionFilters($request), fn ($query) => $query
                ->whereHas('transaction', fn ($query) => AuditFilters::transactions($query, $request)));
    }
}
