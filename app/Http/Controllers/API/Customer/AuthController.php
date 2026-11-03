<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Customer\UnlockRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerAuthService;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer sessions: NIS or account number + PIN starts one. After a login
 * with a temporary PIN the session can only replace the PIN; afterwards the
 * PIN unlocks the app while the session lasts and authorizes withdrawals.
 */
class AuthController extends Controller
{
    public function __construct(private CustomerAuthService $auth)
    {
    }

    /**
     * Start a session with NIS or account number + PIN. "must_change_pin"
     * tells the app to go straight to the PIN change.
     */
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $customer = $this->auth->verifyCredentials($request->validated('identifier'), $request->validated('pin'));

        return response()->json(['data' => $this->auth->startSession($customer)]);
    }

    /**
     * Unlock the app with the PIN during a session. Wrong PINs count toward
     * the shared PIN lock.
     */
    public function unlock(UnlockRequest $request, PinService $pins): JsonResponse
    {
        $pins->verify($request->user(), $request->validated('pin'));

        return response()->json(['message' => 'PIN benar.']);
    }

    public function me(Request $request): CustomerResource
    {
        return new CustomerResource($request->user()->load('major'));
    }

    /**
     * Revoke only the token used for this request (and the push token the
     * phone registered with it).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /**
     * Revoke every session of this customer, e.g. after losing a phone. Their
     * push tokens go with them.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Berhasil keluar dari semua perangkat.']);
    }
}
