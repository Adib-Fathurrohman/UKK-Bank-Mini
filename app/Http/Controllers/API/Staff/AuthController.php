<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a Sanctum token for an administrator, supervisor or teller.
     */
    public function login(StaffLoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->validated('username'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => 'Akun staf ini sudah dinonaktifkan.',
            ]);
        }

        return response()->json([
            'data' => [
                'actor' => 'staff',
                'token' => $user->createToken('staff-portal')->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new StaffResource($user),
            ],
        ]);
    }

    public function me(Request $request): StaffResource
    {
        return new StaffResource($request->user());
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
