<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Writes an amount in Indonesian words, as required on deposit and withdrawal
 * slips ("angka dan terbilang bernilai sama").
 *
 *   Terbilang::rupiah(1500000) === 'satu juta lima ratus ribu rupiah'
 */
final class Terbilang
{
    private const UNITS = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function rupiah(int $amount): string
    {
        return self::words($amount).' rupiah';
    }

    public static function words(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Terbilang only supports non-negative numbers.');
        }

        return $number === 0 ? 'nol' : trim(preg_replace('/\s+/', ' ', self::convert($number)));
    }

    private static function convert(int $n): string
    {
        return match (true) {
            $n < 12 => self::UNITS[$n],
            $n < 20 => self::convert($n - 10).' belas',
            $n < 100 => self::convert(intdiv($n, 10)).' puluh '.self::convert($n % 10),
            $n < 200 => 'seratus '.self::convert($n - 100),
            $n < 1000 => self::convert(intdiv($n, 100)).' ratus '.self::convert($n % 100),
            $n < 2000 => 'seribu '.self::convert($n - 1000),
            $n < 1000000 => self::convert(intdiv($n, 1000)).' ribu '.self::convert($n % 1000),
            $n < 1000000000 => self::convert(intdiv($n, 1000000)).' juta '.self::convert($n % 1000000),
            $n < 1000000000000 => self::convert(intdiv($n, 1000000000)).' miliar '.self::convert($n % 1000000000),
            default => self::convert(intdiv($n, 1000000000000)).' triliun '.self::convert($n % 1000000000000),
        };
    }
}
