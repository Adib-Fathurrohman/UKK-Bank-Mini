<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CompletePinResetRequest;
use App\Http\Requests\Customer\PinResetRequestRequest;
use App\Http\Resources\PinResetRequestResource;
use App\Models\PinResetRequest;
use App\Services\CustomerAuthService;
use App\Services\PinResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forgotten PIN, from the customer's side: ask for a reset, watch its status,
 * cancel it, and set the new PIN once an administrator approved and the
 * waiting period is over. Everything is scoped to the token's owner.
 */
class PinResetController extends Controller
{
    public function __construct(private PinResetService $resets)
    {
    }

    /**
     * The open request, or `data: null` when there is none.
     */
    public function show(Request $request): JsonResponse
    {
        $open = $this->resets->openFor($request->user());

        return response()->json(['data' => $open ? new PinResetRequestResource($open->load('customer')) : null]);
    }

    /**
     * Needs a fresh Firebase ID token of the bound account, so an unattended
     * phone with a running session is not enough to start a reset.
     */
    public function store(PinResetRequestRequest $request, CustomerAuthService $auth): JsonResponse
    {
        $auth->verifyReauthentication($request->user(), $request->validated('id_token'));

        $reset = $this->resets->request($request->user());

        return (new PinResetRequestResource($reset->load('customer')))->response()->setStatusCode(201);
    }

    /**
     * Withdraw the request. Needs only the session: cancelling is always the safe direction.
     */
    public function cancel(Request $request, PinResetRequest $pinResetRequest): PinResetRequestResource
    {
        $this->ensureOwn($request, $pinResetRequest);

        return new PinResetRequestResource($this->resets->cancel($pinResetRequest)->load('customer'));
    }

    public function complete(CompletePinResetRequest $request, PinResetRequest $pinResetRequest, CustomerAuthService $auth): JsonResponse
    {
        $this->ensureOwn($request, $pinResetRequest);

        $auth->verifyReauthentication($request->user(), $request->validated('id_token'));
        $this->resets->complete($pinResetRequest, $request->user(), $request->validated('pin'));

        return response()->json(['message' => 'PIN berhasil diganti.']);
    }

    /**
     * Other customers' requests do not exist from here.
     */
    private function ensureOwn(Request $request, PinResetRequest $pinResetRequest): void
    {
        abort_unless($pinResetRequest->customer_id === $request->user()->id, 404);
    }
}
