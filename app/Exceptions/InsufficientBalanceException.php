<?php

namespace App\Exceptions;

class InsufficientBalanceException extends BankingException
{
    public function __construct(public readonly int $minBalance, public readonly int $maxWithdrawal)
    {
        parent::__construct(sprintf(
            'Saldo tidak mencukupi. Saldo minimum Rp%s harus tetap tersisa; penarikan maksimal Rp%s.',
            number_format($minBalance, 0, ',', '.'),
            number_format($maxWithdrawal, 0, ',', '.'),
        ));
    }
}
