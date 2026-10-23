<?php

namespace App\Http\Controllers\API\Audit;

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Http\Controllers\Controller;
use App\Http\Resources\JournalResource;
use App\Models\AccountingJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only accounting journal for administrators and supervisors (FR-Acc-03).
 * There are deliberately no write endpoints.
 */
class JournalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $journals = $this->filtered($request)
            ->with('transaction')
            ->latest('id')
            ->paginate($this->perPage($request, 30));

        return JournalResource::collection($journals);
    }

    /**
     * Debit and credit totals per account. Total debit must always equal total credit.
     */
    public function summary(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)
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

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced' => $totalDebit === $totalCredit,
            ],
        ]);
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'transaction_id' => ['nullable', 'integer'],
            'account_code' => ['nullable', Rule::enum(AccountCode::class)],
            'position' => ['nullable', Rule::enum(Position::class)],
        ]);

        return AccountingJournal::query()
            ->when($request->date('from'), fn ($query, $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($query, $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($request->input('transaction_id'), fn ($query, $id) => $query->where('transaction_id', $id))
            ->when($request->input('account_code'), fn ($query, $code) => $query->where('account_code', $code))
            ->when($request->input('position'), fn ($query, $position) => $query->where('position', $position));
    }
}
