<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\PushTokenRequest;
use App\Models\CustomerPushToken;
use Illuminate\Http\JsonResponse;

/**
 * The phone's FCM registration token, so the backend can push withdrawal
 * requests. Each token belongs to the session that registered it.
 */
class PushTokenController extends Controller
{
    /**
     * Register (or move to this session) the phone's token. A token seen
     * before, even for another customer on a shared phone, now belongs only to
     * this customer and session.
     */
    public function store(PushTokenRequest $request): JsonResponse
    {
        $pushToken = CustomerPushToken::updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'customer_id' => $request->user()->id,
                'personal_access_token_id' => $request->user()->currentAccessToken()->getKey(),
                'platform' => $request->validated('platform'),
            ],
        );

        return response()->json(['message' => 'Notifikasi aktif di perangkat ini.'], $pushToken->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Stop pushes to this phone (e.g. the customer turned notifications off).
     * Logging out does this too.
     */
    public function destroy(PushTokenRequest $request): JsonResponse
    {
        $request->user()->pushTokens()->where('token', $request->validated('token'))->delete();

        return response()->json(['message' => 'Notifikasi dimatikan di perangkat ini.']);
    }
}
