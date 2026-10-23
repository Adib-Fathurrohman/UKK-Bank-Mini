<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A business rule refused the request (e.g. not enough balance).
 * Laravel renders these as 422 with the message, so clients get one error shape.
 */
abstract class BankingException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
