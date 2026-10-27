<?php

namespace App\Support;

use InvalidArgumentException;

final class Cash
{
    /**
     * Split an amount into the fewest notes and coins, largest first.
     *
     * @return array<int, int> denomination => quantity
     */
    public static function breakdown(int $amount): array
    {
        $counts = [];
        $remaining = $amount;

        foreach (config('bank.denominations') as $denomination) {
            if ($remaining >= $denomination) {
                $counts[$denomination] = intdiv($remaining, $denomination);
                $remaining %= $denomination;
            }
        }

        if ($remaining !== 0) {
            throw new InvalidArgumentException("Rp{$amount} cannot be paid with the configured denominations.");
        }

        return $counts;
    }
}
