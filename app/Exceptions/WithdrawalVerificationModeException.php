<?php

namespace App\Exceptions;

/**
 * The requested way of verifying a withdrawal is switched off by the
 * "withdrawal_verification" setting.
 */
class WithdrawalVerificationModeException extends BankingException
{
    public static function terminalDisabled(): self
    {
        return new self('Penarikan harus disetujui nasabah melalui aplikasi. Buat permintaan persetujuan penarikan untuk nasabah.');
    }

    public static function mobileDisabled(): self
    {
        return new self('Persetujuan penarikan melalui aplikasi nasabah sedang tidak diaktifkan.');
    }
}
