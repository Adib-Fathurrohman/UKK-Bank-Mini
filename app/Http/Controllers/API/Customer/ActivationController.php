<?php

namespace App\Http\Controllers\API\Customer;

use App\Contracts\FirebaseAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ActivateRequest;
use App\Services\CustomerActivationService;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;

/**
 * First-time activation in the app. Public endpoint: the Firebase ID token
 * (verified email) and the NIS are the proof.
 */
class ActivationController extends Controller
{
    /**
     * Bind the Firebase account to the customer with this NIS and start a
     * session. The app then asks the customer to create their PIN.
     */
    public function __invoke(ActivateRequest $request, FirebaseAuth $firebase, CustomerActivationService $activation, CustomerAuthService $auth): JsonResponse
    {
        $identity = $firebase->verifyIdToken($request->validated('id_token'));
        $customer = $activation->activate($request->validated('nis'), $identity);

        return response()->json(['data' => $auth->startSession($customer)]);
    }
}
