<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A withdrawal approval request for a customer who has not activated the
 * customer app: nobody could approve it, so it would only expire. Such a
 * customer has no PIN yet either, so no withdrawal is possible at all. The
 * code lets the teller screen show its own hint.
 */
class CustomerAppNotActivatedException extends BankingException
{
    public const CODE = 'customer_app_not_activated';

    public function __construct()
    {
        parent::__construct('Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga belum dapat melakukan penarikan. Minta nasabah mengaktifkan akun di aplikasi dan membuat PIN terlebih dahulu.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 422);
    }
}
