<?php

namespace App\Http\Controllers\API\Teller;

use App\Exceptions\WithdrawalVerificationModeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teller\CorrectionRequest;
use App\Http\Requests\Teller\StoreTransactionRequest;
use App\Http\Requests\Teller\WithdrawalRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\PdfReportService;
use App\Services\SettingsService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money endpoints accept an optional "Idempotency-Key" header. Sending the same
 * key again returns the original transaction (200, header Idempotent-Replayed)
 * instead of recording it twice.
 */
class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactions)
    {
    }

    /**
     * The logged-in teller's own transactions for one day (default: today).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['date' => ['nullable', 'date']]);

        $date = Carbon::parse($request->input('date', 'today'));

        $transactions = $request->user()->transactions()
            ->with(['customer', 'correction', 'authorizer'])
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->latest('id')
            ->paginate($this->perPage($request, 50));

        return TransactionResource::collection($transactions);
    }

    public function deposit(StoreTransactionRequest $request): JsonResponse
    {
        return $this->respond($this->transactions->deposit(
            $this->customerFrom($request),
            $request->integer('amount'),
            $request->user(),
            $this->idempotencyKey($request),
        ));
    }

    /**
     * Withdrawal with the PIN typed on the teller terminal. Only allowed when the
     * "withdrawal_verification" setting is "terminal" or "both"; by default the
     * customer approves withdrawals in the mobile app instead.
     */
    public function withdraw(WithdrawalRequest $request, SettingsService $settings): JsonResponse
    {
        if (! $settings->allowsTerminalWithdrawalVerification()) {
            throw WithdrawalVerificationModeException::terminalDisabled();
        }

        return $this->respond($this->transactions->withdraw(
            $this->customerFrom($request),
            $request->integer('amount'),
            $request->user(),
            $request->validated('pin'),
            $this->idempotencyKey($request),
        ));
    }

    /**
     * Reverse one of the teller's own transactions from the running shift.
     */
    public function correct(CorrectionRequest $request, Transaction $transaction): JsonResponse
    {
        // Other tellers' transactions do not exist from here.
        abort_unless($transaction->user_id === $request->user()->id, 404);

        $correction = $this->transactions->correct(
            $transaction,
            $request->user(),
            $request->supervisor(),
            $request->validated('reason'),
            $this->idempotencyKey($request),
        );

        return $this->respond($correction->load(['corrects', 'authorizer']));
    }

    /**
     * Printable receipt (struk) for one of the teller's own transactions.
     */
    public function receipt(Request $request, Transaction $transaction, PdfReportService $pdf): Response
    {
        abort_unless($transaction->user_id === $request->user()->id, 404);

        return $pdf->receipt($transaction);
    }

    private function customerFrom(StoreTransactionRequest $request): Customer
    {
        return Customer::where('account_number', $request->validated('account_number'))->firstOrFail();
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return null;
        }

        Validator::make(['Idempotency-Key' => $key], [
            'Idempotency-Key' => ['string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ])->validate();

        return $key;
    }

    /**
     * 201 for a new transaction, 200 plus Idempotent-Replayed for a repeated key.
     */
    private function respond(Transaction $transaction): JsonResponse
    {
        $response = (new TransactionResource($transaction))->response();

        return $transaction->wasRecentlyCreated
            ? $response
            : $response->header('Idempotent-Replayed', 'true');
    }
}
