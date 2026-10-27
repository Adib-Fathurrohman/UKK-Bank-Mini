<?php

namespace App\Support;

final class Rupiah
{
    /**
     * e.g. 1500000 → "Rp1.500.000", -5000 → "-Rp5.000".
     */
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp'.number_format(abs($amount), 0, ',', '.');
    }
}
