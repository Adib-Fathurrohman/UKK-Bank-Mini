<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\API\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Auth\FirebaseLoginRequest;
use App\Http\Requests\Auth\StaffLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The single login gateway for all four roles (soal 1: "satu pintu gerbang login terpusat").
 *
 * Staff send username + password. Customers send a Firebase ID token
 * (id_token); identifier (NIS or account number) + PIN no longer starts a
 * customer session and only reports whether the account must be activated.
 * The response's "actor" tells the client which portal to open.
 */
class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->filled('username')) {
            return app(StaffAuthController::class)->login(app(StaffLoginRequest::class));
        }

        if ($request->filled('id_token')) {
            return app(CustomerAuthController::class)->firebaseLogin(app(FirebaseLoginRequest::class));
        }

        if ($request->filled('identifier')) {
            return app(CustomerAuthController::class)->login(app(CustomerLoginRequest::class));
        }

        throw ValidationException::withMessages([
            'username' => 'Isi username dan password (staf), atau masuk dengan akun Google/email (nasabah).',
        ]);
    }
}
