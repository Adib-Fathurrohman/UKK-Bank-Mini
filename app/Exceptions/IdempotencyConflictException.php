<?php

namespace App\Exceptions;

/**
 * An Idempotency-Key was reused for a different transaction.
 */
class IdempotencyConflictException extends BankingException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key ini sudah dipakai untuk transaksi lain. Gunakan key baru untuk transaksi baru.');
    }
}
