<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A staff password or email step was refused. Rendered as {"message", "code"}.
 */
class StaffPasswordException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * One answer for an unknown email, an unverified one and a wrong or expired
     * token, so the reset page does not reveal which emails exist.
     */
    public static function invalidReset(): self
    {
        return new self('Tautan atur ulang password tidak valid atau sudah kedaluwarsa. Minta tautan baru.', 'password_reset_invalid');
    }

    public static function wrongPassword(): self
    {
        return new self('Password saat ini salah.', 'current_password_invalid');
    }

    public static function noEmail(): self
    {
        return new self('Akun ini belum memiliki email.', 'email_missing');
    }

    public static function alreadyVerified(): self
    {
        return new self('Email ini sudah terverifikasi.', 'email_already_verified', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
