<?php

namespace App\Exceptions;

use App\Enums\AccountStatus;

class AccountNotActiveException extends BankingException
{
    public function __construct(public readonly AccountStatus $status)
    {
        parent::__construct("Rekening berstatus {$status->value} dan tidak dapat bertransaksi.");
    }
}
