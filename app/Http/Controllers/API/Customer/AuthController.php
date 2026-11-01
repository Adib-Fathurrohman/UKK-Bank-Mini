<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\AccountStatus;
use App\Exceptions\InvalidPinException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private PinService $pins)
    {
    }

    /**
     * Issue a Sanctum token for a student using their NIS or account number and PIN.
     *
     * The PIN is checked by PinService, so wrong PINs here count toward the same
     * lock as withdrawal approvals and PIN changes (3 wrong in a row lock it).
     */
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $identifier = $request->validated('identifier');

        // Same precedence as the teller lookup: account number first, then NIS.
        $customer = Customer::where('account_number', $identifier)->first()
            ?? Customer::where('nis', $identifier)->first();

        if (! $customer) {
            throw $this->failed();
        }

        try {
            $this->pins->verify($customer, $request->validated('pin'));
        } catch (InvalidPinException $e) {
            // Remaining attempts are not revealed at login; only an actual lock is.
            throw $e->lockedUntil
                ? ValidationException::withMessages([
                    'identifier' => 'Terlalu banyak percobaan PIN salah. Coba lagi pukul '.$e->lockedUntil->format('H:i').'.',
                ])
                : $this->failed();
        }

        if ($customer->account_status === AccountStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'identifier' => 'Rekening ini sudah tidak aktif.',
            ]);
        }

        return response()->json([
            'data' => [
                'actor' => 'customer',
                'token' => $customer->createToken('customer-portal')->plainTextToken,
                'token_type' => 'Bearer',
                'customer' => new CustomerResource($customer->load('major')),
            ],
        ]);
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

    private function failed(): ValidationException
    {
        return ValidationException::withMessages([
            'identifier' => 'NIS/nomor rekening atau PIN salah.',
        ]);
    }
}
