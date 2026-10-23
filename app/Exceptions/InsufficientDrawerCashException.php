<?php

namespace App\Exceptions;

class InsufficientDrawerCashException extends BankingException
{
    public function __construct(public readonly int $available)
    {
        parent::__construct(sprintf(
            'Kas di laci teller tidak mencukupi. Maksimal yang dapat dipindahkan Rp%s.',
            number_format(max($available, 0), 0, ',', '.'),
        ));
    }
}
