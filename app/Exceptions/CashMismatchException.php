<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The physical cash counted at closing differs from the system balance
 * (saldo awal + setoran - penarikan). The report stays locked until they match.
 */
class CashMismatchException extends BankingException
{
    public function __construct(public readonly int $systemBalance, public readonly int $physicalBalance)
    {
        $difference = $physicalBalance - $systemBalance;

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
        ], 422);
    }
}
