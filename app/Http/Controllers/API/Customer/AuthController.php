<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a Sanctum token for a student using their NIS or account number and PIN.
     */
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $identifier = $request->validated('identifier');
        $pin = $request->validated('pin');

        // A value could in theory match one customer's NIS and another's account
        // number, so only accept the customer whose PIN actually matches.
        $customer = Customer::where('nis', $identifier)
            ->orWhere('account_number', $identifier)
            ->get()
            ->first(fn (Customer $customer) => Hash::check($pin, $customer->pin));

        if (! $customer) {
            throw ValidationException::withMessages([
                'identifier' => 'NIS/nomor rekening atau PIN salah.',
            ]);
        }

        if ($customer->account_status === AccountStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'identifier' => 'Rekening ini sudah tidak aktif.',
            ]);
        }

        return response()->json([
            'data' => [
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
}
