<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The physical cash counted at closing differs from the system balance
 * (saldo awal + setoran - penarikan). The report stays locked until they match.
 */
class CashMismatchException extends BankingException
{
    /**
     * @param  array<int, array{expected: int, counted: int, difference: int}>  $denominations  per-denomination differences (tracked shifts)
     */
    public function __construct(public readonly int $systemBalance, public readonly int $physicalBalance, public readonly array $denominations = [])
    {
        $difference = $physicalBalance - $systemBalance;

        if ($difference === 0) {
            // Right total, wrong notes: e.g. two Rp50.000 counted where the drawer holds one Rp100.000.
            $parts = [];
            foreach ($denominations as $denomination => $row) {
                $parts[] = sprintf('Rp%s %s %d', number_format($denomination, 0, ',', '.'), $row['difference'] > 0 ? 'lebih' : 'kurang', abs($row['difference']));
            }
            parent::__construct('Jumlah uang sesuai, tetapi pecahannya tidak sesuai isi laci: '.implode(', ', $parts).'. Periksa ulang hitungan per pecahan.');

            return;
        }

        parent::__construct(sprintf(
            'Uang fisik %s Rp%s dari saldo sistem. Periksa ulang hitungan dan slip; laporan tidak dapat ditutup atau dicetak sampai sesuai.',
            $difference > 0 ? 'lebih' : 'kurang',
            number_format(abs($difference), 0, ',', '.'),
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'system_balance' => $this->systemBalance,
            'physical_balance' => $this->physicalBalance,
            'difference' => $this->physicalBalance - $this->systemBalance,
            // Tracked shifts: {denomination: {expected, counted, difference}} for every denomination that differs.
            'denominations' => (object) $this->denominations,
        ], 422);
    }
}
