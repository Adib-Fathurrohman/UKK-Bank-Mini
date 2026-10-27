<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Indonesian school years run from July to June: 2026/2027 starts on 1 July 2026.
 */
final class SchoolYear
{
    public const FIRST_MONTH = 7;

    /**
     * The calendar year in which the school year containing $date started.
     */
    public static function startYear(CarbonInterface $date): int
    {
        return $date->month >= self::FIRST_MONTH ? $date->year : $date->year - 1;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} First and last moment of the school year.
     */
    public static function range(int $startYear): array
    {
        $start = CarbonImmutable::create($startYear, self::FIRST_MONTH, 1)->startOfDay();

        return [$start, $start->addYear()->subDay()->endOfDay()];
    }

    /**
     * e.g. "2026/2027".
     */
    public static function label(int $startYear): string
    {
        return $startYear.'/'.($startYear + 1);
    }
}
