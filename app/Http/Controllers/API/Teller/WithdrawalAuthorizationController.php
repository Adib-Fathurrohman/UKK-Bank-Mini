<?php

namespace App\Http\Controllers\API\Teller;

use App\Enums\WithdrawalAuthorizationStatus;
use App\Exceptions\WithdrawalVerificationModeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teller\StoreWithdrawalAuthorizationRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WithdrawalAuthorizationResource;
use App\Models\Customer;
use App\Models\WithdrawalAuthorization;
use App\Services\SettingsService;
use App\Services\TransactionService;
use App\Services\WithdrawalAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Withdrawals the customer approves in the mobile app: the teller creates the
 * request, polls its status, and completes it after approval. The teller never
 * sees or sends the customer's PIN.
 */
class WithdrawalAuthorizationController extends Controller
{
    public function __construct(private WithdrawalAuthorizationService $authorizations)
    {
    }

    /**
     * The teller's requests of today, newest first; ?status=open returns only
     * the one still in progress (to resume after a page reload).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:open']]);

        $teller = $request->user();
        $this->authorizations->expireOverdue(WithdrawalAuthorization::where('teller_id', $teller->id));

        $authorizations = WithdrawalAuthorization::where('teller_id', $teller->id)
            ->with('customer')
            ->when($request->input('status') === 'open',
                fn ($query) => $query->whereIn('status', WithdrawalAuthorizationStatus::open()),
                fn ($query) => $query->where('created_at', '>=', today()))
            ->latest('id')
            ->paginate($this->perPage($request));

        return WithdrawalAuthorizationResource::collection($authorizations);
    }

    public function store(StoreWithdrawalAuthorizationRequest $request, SettingsService $settings): WithdrawalAuthorizationResource
    {
        if (! $settings->allowsMobileWithdrawalVerification()) {
            throw WithdrawalVerificationModeException::mobileDisabled();
        }

        $customer = Customer::where('account_number', $request->validated('account_number'))->firstOrFail();

        $authorization = $this->authorizations->create($request->user(), $customer, $request->integer('amount'));

        return new WithdrawalAuthorizationResource($authorization->load('customer'));
    }

    /**
     * Status for polling.
     */
    public function show(Request $request, WithdrawalAuthorization $withdrawalAuthorization): WithdrawalAuthorizationResource
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        return new WithdrawalAuthorizationResource($this->authorizations->current($withdrawalAuthorization)->load('customer'));
    }

    public function cancel(Request $request, WithdrawalAuthorization $withdrawalAuthorization): WithdrawalAuthorizationResource
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        return new WithdrawalAuthorizationResource($this->authorizations->cancel($withdrawalAuthorization)->load('customer'));
    }

    /**
     * Execute the approved withdrawal. Safe to retry: 201 the first time, then
     * 200 with the same transaction.
     */
    public function complete(Request $request, WithdrawalAuthorization $withdrawalAuthorization, TransactionService $transactions): JsonResponse
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        $transaction = $transactions->completeAuthorizedWithdrawal($withdrawalAuthorization, $request->user());

        return (new TransactionResource($transaction->load('withdrawalAuthorization')))->response();
    }

    /**
     * Other tellers' requests do not exist from here.
     */
    private function ensureOwn(Request $request, WithdrawalAuthorization $authorization): void
    {
        abort_unless($authorization->teller_id === $request->user()->id, 404);
    }
}
