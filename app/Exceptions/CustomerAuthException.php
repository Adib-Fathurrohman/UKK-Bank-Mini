<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A customer login, activation or PIN setup was refused.
 *
 * Rendered as {"message", "code", ...extra} so the app can react to the
 * machine-readable code. The codes only describe the caller's own identity;
 * none of them reveals whether some other email or account exists.
 */
class CustomerAuthException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function firebaseTokenInvalid(): self
    {
        return new self('Sesi login tidak valid atau sudah berakhir. Silakan masuk lagi.', 'firebase_token_invalid', 401);
    }

    public static function providerNotAllowed(): self
    {
        return new self('Metode masuk ini tidak didukung. Gunakan Google atau email dan password.', 'provider_not_allowed', 403);
    }

    public static function unavailable(): self
    {
        return new self('Layanan login sedang tidak tersedia. Coba lagi beberapa saat lagi.', 'auth_unavailable', 503);
    }

    public static function emailNotVerified(): self
    {
        return new self('Verifikasi email Anda terlebih dahulu, lalu coba lagi.', 'email_not_verified', 403);
    }

    public static function identityAlreadyLinked(): self
    {
        return new self('Akun Google atau email ini sudah terhubung ke rekening lain.', 'identity_already_linked', 409);
    }

    public static function accountNotLinked(): self
    {
        return new self(
            'Akun ini belum terhubung ke rekening. Aktifkan rekening dengan memasukkan NIS Anda.',
            'account_not_linked',
            403,
        );
    }

    public static function accountInactive(): self
    {
        return new self('Rekening ini sudah tidak aktif.', 'account_inactive', 403);
    }

    /**
     * Activation: no account with this NIS is registered to the signed-in
     * email. One answer for an unknown NIS and for another email, so the
     * response does not reveal which NIS numbers exist or whose email is whose.
     */
    public static function activationMismatch(): self
    {
        return new self(
            'NIS tidak cocok dengan email akun ini. Masuk dengan email yang didaftarkan di bank mini, atau hubungi petugas.',
            'activation_mismatch',
            403,
        );
    }

    public static function alreadyActivated(): self
    {
        return new self(
            'Rekening ini sudah diaktifkan dengan akun lain. Masuk dengan akun tersebut, atau hubungi petugas bank mini.',
            'already_activated',
            409,
        );
    }

    /**
     * PIN setup with a Firebase account other than the one bound to the session's customer.
     */
    public static function identityMismatch(): self
    {
        return new self('Masuk dengan akun Google atau email yang terhubung ke rekening ini.', 'identity_mismatch', 403);
    }

    public static function pinAlreadySet(): self
    {
        return new self('PIN sudah dibuat. Gunakan ubah PIN untuk menggantinya.', 'pin_already_set', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->extra,
        ], $this->status);
    }
}
