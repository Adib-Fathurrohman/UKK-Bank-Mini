<?php

namespace App\Http\Controllers\API\Customer;

use App\Contracts\FirebaseAuth;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Customer\CompleteActivationRequest;
use App\Http\Requests\Customer\InspectActivationRequest;
use App\Services\CustomerActivationService;
use App\Services\CustomerAuthService;
use App\Support\Email;
use Illuminate\Http\JsonResponse;

/**
 * First-time activation from the invitation link. Public endpoints: the
 * invitation token (and for completion, a Firebase ID token) is the proof.
 */
class ActivationController extends Controller
{
    public function __construct(private CustomerActivationService $activation)
    {
    }

    /**
     * What the activation page shows before the customer signs in: the
     * registered email (the only one that can be used) and the expiry.
     */
    public function show(InspectActivationRequest $request): JsonResponse
    {
        $record = $this->activation->inspect($request->validated('token'));

        return response()->json([
            'data' => [
                'full_name' => $record->customer->full_name,
                'account_number' => $record->customer->account_number,
                'email' => $record->email,
                'expires_at' => $record->expires_at,
                'providers' => config('services.firebase.providers'),
            ],
        ]);
    }

    /**
     * Bind the Firebase account to the invited customer and start a session.
     */
    public function complete(CompleteActivationRequest $request, FirebaseAuth $firebase, CustomerAuthService $auth): JsonResponse
    {
        // The link is checked first, so an invalid link is reported as such
        // even when the Firebase token is also bad.
        $this->activation->inspect($request->validated('token'));

        $identity = $firebase->verifyIdToken($request->validated('id_token'));
        $customer = $this->activation->activate($request->validated('token'), $identity);

        return response()->json(['data' => $auth->startSession($customer)]);
    }

    /**
     * A customer who has not activated yet asks for a new link, proving who
     * they are with NIS/account number + PIN (shared PIN lock). The link goes
     * only to the email the school registered.
     */
    public function resend(CustomerLoginRequest $request, CustomerAuthService $auth): JsonResponse
    {
        $customer = $auth->verifyPinCredentials($request->validated('identifier'), $request->validated('pin'));

        if ($customer->isActivated()) {
            throw CustomerAuthException::firebaseLoginRequired();
        }

        if (! $customer->email) {
            throw CustomerAuthException::activationRequired(null);
        }

        $invitation = $this->activation->invite($customer);

        return response()->json([
            'message' => $invitation['mail_sent']
                ? 'Tautan aktivasi baru telah dikirim ke '.Email::mask($invitation['sent_to']).'.'
                : 'Tautan aktivasi gagal dikirim. Coba lagi nanti atau hubungi administrator bank mini.',
            'data' => [
                'email' => Email::mask($invitation['sent_to']),
                'expires_at' => $invitation['expires_at'],
                'mail_sent' => $invitation['mail_sent'],
            ],
        ]);
    }
}
