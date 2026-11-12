<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Services\StaffAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The logged-in staff member's own email and password (every role, administrators included).
 */
class AccountController extends Controller
{
    public function __construct(private StaffAccountService $accounts)
    {
    }

    /**
     * Set or change the own email; needs the current password. A new address
     * gets a verification link and is unverified until it is opened.
     */
    public function updateEmail(Request $request): StaffResource
    {
        $staff = $request->user();
        $data = $request->validate([
            'email' => ['present', 'nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)],
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ], [
            'current_password.current_password' => 'Password saat ini salah.',
        ]);

        return new StaffResource($this->accounts->assignEmail($staff, $data['email']));
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $this->accounts->resendVerification($request->user());

        return response()->json(['message' => 'Tautan verifikasi telah dikirim ke '.$request->user()->email.'.'], 202);
    }

    /**
     * Change the password with the current one. Other sessions are logged out.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ]);

        $this->accounts->changePassword(
            $request->user(),
            $data['current_password'],
            $data['password'],
            $request->user()->currentAccessToken()?->getKey(),
        );

        return response()->json(['message' => 'Password berhasil diubah. Sesi di perangkat lain telah dikeluarkan.']);
    }
}
