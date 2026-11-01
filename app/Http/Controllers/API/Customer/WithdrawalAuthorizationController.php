<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\WithdrawalAuthorizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ApproveWithdrawalAuthorizationRequest;
use App\Http\Resources\CustomerWithdrawalAuthorizationResource;
use App\Models\WithdrawalAuthorization;
use App\Services\WithdrawalAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer reviews withdrawals a teller started for their account and
 * approves them with their PIN, or rejects them. The customer can never create
 * a withdrawal (soal 1d: nasabah has no access to "modul input transaksi bank").
 */
class WithdrawalAuthorizationController extends Controller
{
    public function __construct(private WithdrawalAuthorizationService $authorizations)
    {
    }

    /**
     * Requests waiting for this customer (pending, or approved and waiting for the teller).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $request->user();
        $this->authorizations->expireOverdue(WithdrawalAuthorization::where('customer_id', $customer->id));

        $authorizations = WithdrawalAuthorization::where('customer_id', $customer->id)
            ->whereIn('status', WithdrawalAuthorizationStatus::open())
            ->with(['teller', 'customer'])
            ->latest('id')
            ->get();

        return CustomerWithdrawalAuthorizationResource::collection($authorizations);
    }

    public function show(Request $request, WithdrawalAuthorization $withdrawalAuthorization): CustomerWithdrawalAuthorizationResource
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        return $this->present($this->authorizations->current($withdrawalAuthorization));
    }

    public function approve(ApproveWithdrawalAuthorizationRequest $request, WithdrawalAuthorization $withdrawalAuthorization): CustomerWithdrawalAuthorizationResource
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        return $this->present($this->authorizations->approve($withdrawalAuthorization, $request->user(), $request->validated('pin')));
    }

    public function reject(Request $request, WithdrawalAuthorization $withdrawalAuthorization): CustomerWithdrawalAuthorizationResource
    {
        $this->ensureOwn($request, $withdrawalAuthorization);

        return $this->present($this->authorizations->reject($withdrawalAuthorization));
    }

    private function present(WithdrawalAuthorization $authorization): CustomerWithdrawalAuthorizationResource
    {
        return new CustomerWithdrawalAuthorizationResource($authorization->load(['teller', 'customer']));
    }

    /**
     * Other customers' requests do not exist from here.
     */
    private function ensureOwn(Request $request, WithdrawalAuthorization $authorization): void
    {
        abort_unless($authorization->customer_id === $request->user()->id, 404);
    }
}
