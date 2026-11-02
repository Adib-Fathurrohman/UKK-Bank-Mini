<?php

namespace App\Exceptions;

use App\Support\Email;
use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A customer login, activation or invitation was refused.
 *
 * Rendered as {"message", "code", ...extra} so the app can react to the
 * machine-readable code. The codes only describe the caller's own identity or
 * link; none of them reveals whether some other email or account exists.
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

    public static function emailMismatch(string $registeredEmail): self
    {
        return new self(
            'Akun ini memakai email yang berbeda dari email yang didaftarkan sekolah. Masuk dengan akun '.Email::mask($registeredEmail).'.',
            'email_mismatch',
            403,
            ['email' => Email::mask($registeredEmail)],
        );
    }

    public static function identityAlreadyLinked(): self
    {
        return new self('Akun Google atau email ini sudah terhubung ke rekening lain.', 'identity_already_linked', 409);
    }

    public static function accountNotLinked(): self
    {
        return new self(
            'Akun ini belum terhubung ke rekening. Aktifkan rekening melalui tautan yang dikirim ke email Anda.',
            'account_not_linked',
            403,
        );
    }

    public static function accountInactive(): self
    {
        return new self('Rekening ini sudah tidak aktif.', 'account_inactive', 403);
    }

    public static function activationInvalid(): self
    {
        return new self('Tautan aktivasi tidak valid atau sudah digunakan.', 'activation_invalid', 404);
    }

    public static function activationExpired(): self
    {
        return new self('Tautan aktivasi sudah kedaluwarsa. Minta tautan baru.', 'activation_expired', 410);
    }

    /**
     * Correct NIS/PIN, but the account has no Firebase login yet.
     */
    public static function activationRequired(?string $email): self
    {
        return new self(
            $email
                ? 'Rekening belum diaktifkan. Buka tautan aktivasi yang dikirim ke '.Email::mask($email).'.'
                : 'Rekening belum diaktifkan dan belum memiliki email. Hubungi administrator bank mini.',
            'activation_required',
            403,
            ['email' => $email ? Email::mask($email) : null],
        );
    }

    /**
     * Correct NIS/PIN for an activated account: the PIN alone no longer starts a session.
     */
    public static function firebaseLoginRequired(): self
    {
        return new self(
            'Masuk dengan akun Google atau email yang terhubung. PIN dipakai untuk membuka aplikasi dan bertransaksi.',
            'firebase_login_required',
            403,
        );
    }

    public static function alreadyActivated(): self
    {
        return new self('Rekening ini sudah diaktifkan.', 'already_activated', 409);
    }

    public static function emailMissing(): self
    {
        return new self('Rekening ini belum memiliki email. Tambahkan email terlebih dahulu.', 'email_missing', 422);
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
