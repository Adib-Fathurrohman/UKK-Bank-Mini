<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A withdrawal for a customer who still has the temporary PIN from the
 * system (new account or administrator reset). That PIN was shown to staff,
 * so it never authorizes money; the customer first replaces it in the app.
 */
class PinChangeRequiredException extends BankingException
{
    public const CODE = 'pin_change_required';

    public function __construct()
    {
        parent::__construct('Nasabah belum mengganti PIN sementara. Minta nasabah masuk ke aplikasi Bank Mini dan mengganti PIN terlebih dahulu.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 422);
    }
}
