<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A withdrawal approval request for a customer who has not activated the
 * customer app: nobody could approve it, so it would only expire. The code
 * lets the teller screen show its own hint.
 */
class CustomerAppNotActivatedException extends BankingException
{
    public const CODE = 'customer_app_not_activated';

    public static function forMode(bool $terminalAvailable): self
    {
        return new self('Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga tidak dapat menyetujui penarikan melalui aplikasi. '
            .($terminalAvailable
                ? 'Lakukan penarikan dengan PIN nasabah di terminal loket.'
                : 'Minta nasabah menyelesaikan aktivasi melalui tautan di email undangan terlebih dahulu.'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 422);
    }
}
