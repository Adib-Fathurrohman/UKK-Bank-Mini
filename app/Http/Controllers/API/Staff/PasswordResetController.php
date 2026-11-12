<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StaffAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public staff endpoints: forgot / reset password, and the email verification link.
 */
class PasswordResetController extends Controller
{
    public function __construct(private StaffAccountService $accounts)
    {
    }

    /**
     * Always the same answer, so the page does not reveal which emails exist.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $this->accounts->sendResetLink($data['email']);

        return response()->json([
            'message' => 'Jika email tersebut terdaftar dan terverifikasi, tautan untuk mengatur ulang password telah dikirim. Periksa kotak masuk dan folder spam.',
        ], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $this->accounts->resetPassword($data['email'], $data['token'], $data['password']);

        return response()->json(['message' => 'Password berhasil diatur ulang. Silakan masuk dengan password baru.']);
    }

    /**
     * Opened from the email (signed, expiring link): verifies and sends the
     * staff member to the login page of the staff web app with the outcome.
     */
    public function verifyEmail(Request $request, int $user, string $hash): RedirectResponse
    {
        $staff = User::find($user);
        $ok = $staff && $this->accounts->verifyEmail($staff, $hash);

        return redirect()->away(config('bank.staff_web_url').'/login?email_verified='.($ok ? '1' : '0'));
    }
}
