<?php

namespace App\Http\Controllers\API\Audit;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\AuditFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only transaction log (real-time log / audit trail) for administrators and supervisors.
 */
class TransactionController extends Controller
{
    /**
     * Exports are limited so a single PDF stays printable.
     */
    public const EXPORT_LIMIT = 5000;

    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = $this->filtered($request)
            ->with(['customer', 'teller', 'authorizer', 'correction'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return TransactionResource::collection($transactions);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['customer', 'teller', 'accountingJournals', 'corrects', 'correction', 'authorizer', 'withdrawalAuthorization']));
    }

    /**
     * The audit trail as a printable PDF with the same filters as the list.
     */
    public function export(Request $request): Response
    {
        $transactions = $this->filtered($request)
            ->with(['customer', 'teller'])
            ->oldest('id')
            ->limit(self::EXPORT_LIMIT + 1)
            ->get();

        return Pdf::loadView('pdf.transactions', [
            'transactions' => $transactions->take(self::EXPORT_LIMIT),
            'truncated' => $transactions->count() > self::EXPORT_LIMIT,
            'filters' => $request->only(array_keys(AuditFilters::rules())),
        ])->setPaper('a4', 'landscape')->download('jejak-audit-transaksi.pdf');
    }

    private function filtered(Request $request): Builder
    {
        $request->validate(AuditFilters::rules());

        return AuditFilters::transactions(
            AuditFilters::dateRange(Transaction::query(), $request, 'created_at'),
            $request,
        );
    }
}
