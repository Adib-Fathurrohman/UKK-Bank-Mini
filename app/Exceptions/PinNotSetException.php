<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The customer has no PIN yet: it is created in the app after activation (and
 * again after an administrator reset). Nothing that needs a PIN works until
 * then, and no wrong attempt is counted.
 */
class PinNotSetException extends BankingException
{
    public const CODE = 'pin_not_set';

    public function __construct()
    {
        parent::__construct('Nasabah belum membuat PIN. PIN dibuat oleh nasabah di aplikasi Bank Mini setelah akun diaktifkan.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 422);
    }
}
