<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirebaseLoginRequest;
use App\Http\Requests\Customer\UnlockRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerAuthService;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer sessions. A session starts only with a verified Firebase login (or
 * activation, see ActivationController); the PIN unlocks the app while the
 * session lasts and authorizes withdrawals.
 */
class AuthController extends Controller
{
    public function __construct(private CustomerAuthService $auth)
    {
    }

    /**
     * Start a session from a Firebase ID token (Google or email/password).
     */
    public function firebaseLogin(FirebaseLoginRequest $request): JsonResponse
    {
        $customer = $this->auth->customerForFirebaseLogin($request->validated('id_token'));

        return response()->json(['data' => $this->auth->startSession($customer)]);
    }

    /**
     * Unlock the app with the PIN during a session. Wrong PINs count toward
     * the shared PIN lock; without a PIN yet the answer is pin_not_set.
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
     * Revoke only the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /**
     * Revoke every session of this customer, e.g. after losing a phone.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Berhasil keluar dari semua perangkat.']);
    }
}
