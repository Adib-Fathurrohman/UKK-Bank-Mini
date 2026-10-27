<?php

namespace App\Exceptions;

/**
 * The teller has no shift in which a transaction (or closing) can be recorded.
 */
class ShiftNotOpenException extends BankingException
{
    public static function none(): self
    {
        return new self('Belum ada shift yang dibuka. Buka shift dengan menghitung saldo awal terlebih dahulu.');
    }

    public static function fromPastDay(string $date): self
    {
        return new self("Shift tanggal {$date} belum ditutup. Tutup shift tersebut sebelum bertransaksi.");
    }

    public static function alreadyClosedToday(): self
    {
        return new self('Shift hari ini sudah ditutup. Transaksi baru tidak dapat dilakukan sampai laporan ditolak supervisor.');
    }
}
