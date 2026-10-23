<?php

namespace App\Exceptions;

class CounterClosedException extends BankingException
{
    public function __construct()
    {
        parent::__construct('Loket sudah ditutup: laporan harian hari ini sudah dikirim. Transaksi baru tidak dapat dilakukan.');
    }
}
